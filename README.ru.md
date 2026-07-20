# rasuvaeff/domain-monitor

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/domain-monitor/v)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/domain-monitor/downloads)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Build](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/domain-monitor/php)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Модульный набор инструментов для мониторинга доменов на PHP 8.3+. Без привязки
к фреймворку, PSR-совместимый, с небольшими иммутабельными DTO и узкими
stateless-сервисами. Каждый чекер делает одну вещь — вы компонуете их по мере
необходимости.

**Проверки:** HTTP-пробы · SSL-сертификаты · WHOIS · DNS · TCP-порты ·
заголовки безопасности · `robots.txt` · sitemap-ы.

**Не входит:** планирование, персистентность, кэширование или асинхронные
раннеры. Пакет предоставляет строительные блоки и оркестратор `DomainMonitor`;
рабочий процесс обеспечивает ваше приложение.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник.

## Требования

- PHP 8.3+
- `ext-openssl`, `ext-simplexml`
- PSR-18 клиент и PSR-17 request factory для HTTP-проверок
- `io-developer/php-whois` (тянет `ext-curl`, `ext-mbstring`)
- `ext-intl` опционально (только для нормализации IDN)
- `ext-sockets` опционально (только для DNS-резолва)

## Установка

```bash
composer require rasuvaeff/domain-monitor
```

Для HTTP-проверок также понадобится реализация PSR-18/PSR-17:

```bash
composer require symfony/http-client nyholm/psr7
```

## Быстрый старт: полная проверка домена

### Самый простой путь: фабрика

`DomainMonitor::create()` собирает все проверки из одного PSR-18 клиента +
PSR-17 фабрики (WHOIS опционален):

```php
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\DomainMonitor;
use Symfony\Component\HttpClient\Psr18Client;

$monitor = DomainMonitor::create(
    httpClient: new Psr18Client(),
    requestFactory: new Psr17Factory(),
    whois: Factory::get()->createWhois(), // omit to disable the WHOIS check
);

$report = $monitor->check(host: 'example.com');

echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

Для гранулярного контроля над набором проверок используйте `DomainMonitorBuilder`:

```php
use Rasuvaeff\DomainMonitor\DomainMonitorBuilder;

$monitor = DomainMonitorBuilder::create()
    ->withHttp(client: new Psr18Client(), requestFactory: new Psr17Factory())
    ->withWhois(Factory::get()->createWhois())
    ->withoutPort()
    ->build();
```

### Через оркестратор (рекомендуется)

```php
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\{
    DnsService,
    DomainMonitor,
    DomainMonitorOptions,
    HttpContentCheckService,
    HttpProbeService,
    PortService,
    RobotsTxtService,
    SecurityHeadersService,
    SitemapService,
    SslCertificateService,
    WhoisService,
};
use Symfony\Component\HttpClient\Psr18Client;

$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$monitor = new DomainMonitor(
    httpProbe: new HttpProbeService(httpClient: $client, requestFactory: $requestFactory),
    ssl: new SslCertificateService(),
    whois: new WhoisService(whois: Factory::get()->createWhois()),
    dns: new DnsService(),
    port: new PortService(),
    securityHeaders: new SecurityHeadersService(),
    robotsTxt: new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory),
    sitemap: new SitemapService(httpClient: $client, requestFactory: $requestFactory),
    content: new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory),
);

$report = $monitor->check(
    host: 'example.com',
    options: new DomainMonitorOptions(timeoutSeconds: 10.0),
);

echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

Сервисы опциональны — передайте `null` (или опустите), чтобы отключить проверку.
Оркестратор переиспользует один HTTP-ответ для пробы + заголовков безопасности +
проверки контента. Упавшие проверки перехватываются, логируются через PSR-3 и
опускаются в отчёте.

### Ручная композиция

```php
use DateTimeImmutable;
use Iodev\Whois\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\DomainMonitor\{
    DnsService,
    DomainHealthReport,
    HttpContentCheckService,
    HttpProbeService,
    PortService,
    RobotsTxtService,
    SecurityHeadersService,
    SitemapService,
    SslCertificateService,
    WhoisService,
};
use Symfony\Component\HttpClient\Psr18Client;

$host = 'example.com';
$client = new Psr18Client();
$requestFactory = new Psr17Factory();

$report = new DomainHealthReport(
    host: $host,
    probe: (new HttpProbeService(httpClient: $client, requestFactory: $requestFactory))
        ->check(url: "https://{$host}"),
    ssl: (new SslCertificateService())->check(host: $host),
    whois: (new WhoisService(whois: Factory::get()->createWhois()))->check(host: $host),
    dns: (new DnsService())->check(host: $host),
    port: (new PortService())->check(host: $host, port: 443),
);

// Aggregate status: worst among checks (OK → WARNING → CRITICAL → UNKNOWN)
echo $report->getStatus()->value;
```

## Чтение отчёта

`getStatus()` — это агрегированный статус (худший среди всех проверок). Чтобы
понять *почему*, обойдите результаты каждой проверки — каждый несёт `CheckName`,
`CheckStatus` и человекочитаемый `reason`:

```php
foreach ($report->getChecks() as $result) {
    printf("%-16s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
}
// probe            ok       HTTP 200
// ssl              ok       Certificate valid, expires in 61 day(s)
// whois            warning  Domain expires in 12 day(s)

$ssl = $report->getCheck(name: CheckName::Ssl); // ?CheckResult
```

### Ошибки против отключённых проверок

Проверка, которая **не настроена** — это `null`. Проверка, которая **запускалась,
но упала** — записывается отдельно: она появляется в `getChecks()` как `UNKNOWN`
(никогда не завышает агрегат) и в `getErrors()`:

```php
if ($report->hasErrors()) {
    foreach ($report->getErrors() as $error) {
        // CheckError { check: CheckName, message: string }
        echo "{$error->check->value}: {$error->message}\n";
    }
}
```

Считайте `getStatus() === CheckStatus::OK` вместе с `hasErrors() === true` как
«OK, но неполно».

### Пороги

По умолчанию SSL становится `CRITICAL` только после истечения срока, а WHOIS
предупреждает за 30 дней. Включите «скорого истечения SSL = warning» (и
настройте окно WHOIS) через `ReportThresholds`:

```php
use Rasuvaeff\DomainMonitor\DomainMonitorOptions;
use Rasuvaeff\DomainMonitor\ReportThresholds;

$report = $monitor->check(
    host: 'example.com',
    options: new DomainMonitorOptions(
        thresholds: ReportThresholds::strict(), // SSL warns 14 days before expiry
        // or: new ReportThresholds(sslWarnDays: 30, whoisWarnDays: 45)
    ),
);
```

`ReportThresholds::default()` точно воспроизводит поведение до версии 1.2.0.

### Сериализация

Каждый DTO результата реализует `JsonSerializable`, поэтому весь отчёт
кодируется одним вызовом — даты как ISO-8601, enum-ы как их значения,
отключённые проверки как `null`:

```php
$json = json_encode($report, JSON_THROW_ON_ERROR);
```

Массив `checks` — это вычисленный снимок (замороженные строки `reason`);
вложенные сырые DTO (`ssl.validUntil`, `whois.expirationDate`) остаются
абсолютными, поэтому сохранённый blob — достоверная запись.

## Обнаружение изменений статуса

`DomainHealthReport` — это одиночный снимок. Чтобы алертить только при
*изменении*, храните предыдущий снимок (хранение — на стороне приложения) и
сравнивайте его с текущим через `ReportComparator` — stateless-хелпер,
возвращающий по одному `StatusTransition` на каждую изменившуюся проверку:

```php
use Rasuvaeff\DomainMonitor\ReportComparator;
use Rasuvaeff\DomainMonitor\TransitionKind;

$comparator = new ReportComparator();

$previous = $storage->latest(host: 'example.com'); // ваше хранилище, при первом запуске может быть null
$current = $monitor->check(host: 'example.com');

$diff = $comparator->compare(previous: $previous ?? $current, current: $current);

if ($diff->hasChanges()) {
    foreach ($diff->getTransitions() as $t) {
        // $t->check, $t->from (?CheckStatus), $t->to (?CheckStatus), $t->kind
        printf("%s: %s -> %s (%s)\n", $t->check->value, $t->from?->value ?? '—', $t->to?->value ?? '—', $t->kind->value);
    }
}

$storage->save(host: 'example.com', report: $current); // для следующего запуска
```

`compare()` возвращает обёртку `ReportDiff` (`hasChanges()`, `getTransitions()`,
`worstTransition()`); `diff()` возвращает сырой `list<StatusTransition>`. Каждый
transition несёт `TransitionKind`:

| Kind | Значение |
|---|---|
| `Appeared` | Проверки не было, теперь есть (`from` = `null`) |
| `Disappeared` | Проверка была, теперь отсутствует (`to` = `null`) |
| `Degraded` | Статус ухудшился (например, `ok → critical`) |
| `Recovered` | Статус улучшился (например, `critical → ok`) |
| `Changed` | Статус изменился в/из `UNKNOWN`, где severity не сравнима |

`ReportComparator` сравнивает по `CheckName` и детерминирован (`diff($r, $r)`
всегда `[]`). Он ничего не знает про расписание, хранение и доставку — передача
transition-ов в webhook-и или realtime-канал остаётся задачей приложения.
Готовый пайплайн (расписание, история, webhook + Centrifugo-алерты,
status-страница) живёт в `rasuvaeff/monitor-dashboard`.

## Сервисы

### HTTP-пробы

```php
use Rasuvaeff\DomainMonitor\HttpProbeOptions;
use Rasuvaeff\DomainMonitor\HttpProbeService;

$probe = (new HttpProbeService(httpClient: $client, requestFactory: $requestFactory))
    ->check(
        url: 'https://example.com',
        options: new HttpProbeOptions(method: 'HEAD', timeoutSeconds: 10.0),
    );

// ProbeResult { status: 200, totalTime: 0.12 }
var_dump($probe->status, $probe->totalTime);
```

`timeoutSeconds` — **best-effort**: у PSR-18 нет стандартного API для таймаута.
Такие клиенты, как Symfony, его учитывают; клиенты вроде сырого Guzzle — могут
не учитывать.

### SSL

```php
use Rasuvaeff\DomainMonitor\SslCertificateService;

$cert = (new SslCertificateService())->check(
    host: 'example.com',
    expectedOrg: 'Example Inc.', // optional org filter
);

if ($cert !== null) {
    echo $cert->daysUntilExpiry();      // e.g. 45
    echo $cert->isExpiringWithin(days: 30); // false
    echo $cert->subjectCn;              // "example.com"
    echo $cert->issuer;                 // "Example CA"
}
```

Примечание: SSL-проверка читает peer-сертификат **без верификации цепочки
доверия** — это инструмент мониторинга, а не PKI-валидатор.

### WHOIS

```php
use Iodev\Whois\Factory;
use Rasuvaeff\DomainMonitor\WhoisService;

$info = (new WhoisService(whois: Factory::get()->createWhois()))
    ->check(host: 'example.com');

// TldInfo { domain, ?registrar, ?expirationDate, states }
echo $info->daysUntilExpiry(); // null if expirationDate missing
```

Фолбэк: если `www.example.com` падает, сервис автоматически ретраит с
`example.com`.

### DNS

```php
use Rasuvaeff\DomainMonitor\DnsService;

$records = (new DnsService())->check(host: 'example.com');

// DnsRecords { a: ['93.184.216.34'], mx: ['...'], ns: ['...'], ... }
var_dump($records->a, $records->mx);
```

### Проверка порта (TCP)

```php
use Rasuvaeff\DomainMonitor\PortService;

$check = (new PortService())->check(host: 'example.com', port: 443, timeoutSeconds: 5.0);
// PortCheck { status: OK, connectTime: 0.04, error: null }
```

### Заголовки безопасности

```php
use Rasuvaeff\DomainMonitor\SecurityHeadersService;

// Pass a PSR-7 ResponseInterface (from a prior HTTP probe)
$headers = (new SecurityHeadersService())->check(response: $response);
// SecurityHeadersCheck { hasHsts: true, hasContentSecurityPolicy: false, ... }
```

### robots.txt

```php
use Rasuvaeff\DomainMonitor\RobotsTxtService;

$robots = (new RobotsTxtService(httpClient: $client, requestFactory: $requestFactory))
    ->check(baseUrl: 'https://example.com');
// RobotsTxtCheck { exists: true, sitemaps: ['https://example.com/sitemap.xml'] }
```

### Sitemap

```php
use Rasuvaeff\DomainMonitor\SitemapService;

$sitemap = (new SitemapService(httpClient: $client, requestFactory: $requestFactory))
    ->check(sitemapUrl: 'https://example.com/sitemap.xml');
// SitemapCheck { exists: true, urlCount: 42 }
```

### Проверка контента

```php
use Rasuvaeff\DomainMonitor\HttpContentCheckService;

$content = (new HttpContentCheckService(httpClient: $client, requestFactory: $requestFactory))
    ->check(
        url: 'https://example.com',
        expectedStatus: 200,
        requiredText: 'Example Domain',     // must be present
        forbiddenText: 'Internal Error',    // must NOT be present
    );
// HttpContentCheck { status: OK, requiredTextFound: true, forbiddenTextFound: false }
```

### Сборка отчёта

```php
use Rasuvaeff\DomainMonitor\{DomainHealthReport, CheckStatus};
use Rasuvaeff\DomainMonitor\ProbeResult;
use Rasuvaeff\DomainMonitor\SslCertificate;

$report = new DomainHealthReport(
    host: 'example.com',
    probe: new ProbeResult(status: 200, totalTime: 0.13),
    ssl: new SslCertificate(/* ... */),
    whois: $tldInfo,
    dns: $dnsRecords,
);
echo $report->getStatus()->value; // 'ok' | 'warning' | 'critical' | 'unknown'
```

## Полный API-справочник

| Класс | Что делает |
|---|---|
| `DomainMonitor` | Оркестратор: запускает все настроенные сервисы, переиспользует HTTP-ответ для пробы + заголовков безопасности + контента → `DomainHealthReport`; фабрика `create()` + реализует `DomainMonitorInterface` |
| `DomainMonitorInterface` | Контракт для `DomainMonitor` — мокать/декорировать |
| `DomainMonitorBuilder` | Fluent-сборка оркестратора с гранулярным контролем (`withHttp`, `withWhois`, `withoutPort`, …) |
| `DomainMonitorOptions` | VO для оркестратора: port, timeout, method, userAgent, expectedOrg, expectedStatus, requiredText, forbiddenText, thresholds |
| `ReportThresholds` | VO: окно предупреждения об истечении SSL (`sslWarnDays`) + окно предупреждения WHOIS (`whoisWarnDays`); `default()` / `strict()` |
| `HostNormalizer` | Нормализация хостов/URL-ов (lowercase, strip scheme/port/path, опционально IDN) |
| `HttpProbeService` | PSR-18 GET/HEAD-проба с замером времени → `ProbeResult`; `probeWithResponse()` для переиспользования ответа |
| `HttpProbeWithResponse` | DTO: `ProbeResult` + `ResponseInterface` (для переиспользования ответа) |
| `HttpProbeOptions` | Конфигурация method, headers, timeout, user-agent для HTTP-проб |
| `ProbeResult` | DTO: `status`, `totalTime` |
| `SslCertificateService` | Чтение удалённого SSL-сертификата; опциональный org-фильтр → `SslCertificate` |
| `SslCertificate` | DTO: `validFrom`, `validUntil`, `subjectCn`, `issuer` + хелперы истечения |
| `WhoisService` | Загрузка и мэппинг данных vendor-а WHOIS → `TldInfo` |
| `TldInfo` | DTO: `domain`, `?registrar`, `?expirationDate`, `states` |
| `DnsService` | Обёртка над `dns_get_record()` → `DnsRecords` |
| `DnsRecords` | DTO: `a`, `aaaa`, `mx`, `ns`, `txt`, `cname` |
| `PortService` | TCP-доступность через `stream_socket_client()` → `PortCheck` |
| `PortCheck` | DTO: `status`, `host`, `port`, `connectTime`, `?error` |
| `SecurityHeadersService` | Проверка HSTS/CSP/XFO/XCTO на PSR-7-ответе → `SecurityHeadersCheck` |
| `SecurityHeadersCheck` | DTO: флаги по каждому заголовку + списки present/missing |
| `RobotsTxtService` | Загрузка `/robots.txt` + извлечение Sitemap-хинтов → `RobotsTxtCheck` |
| `RobotsTxtCheck` | DTO: `exists`, `httpStatus`, `sitemaps[]` |
| `SitemapService` | Загрузка sitemap + подсчёт `<url>`-записей → `SitemapCheck` |
| `SitemapCheck` | DTO: `exists`, `httpStatus`, `urlCount` |
| `HttpContentCheckService` | Проверка статус-кода + обязательных/запрещённых ключевых слов → `HttpContentCheck`; `checkFromResponse()` для переиспользования ответа |
| `HttpContentCheck` | DTO: `status`, `httpStatus`, `?finalUrl`, текстовые флаги |
| `DomainHealthReport` | Составной DTO для всех результатов проверок; `getStatus()` агрегат, `getChecks()`/`getCheck()` по каждой проверке, `getErrors()`/`hasErrors()`, `JsonSerializable` |
| `CheckResult` | DTO: `check` (`CheckName`), `status` (`CheckStatus`), `reason` (человекочитаемый) |
| `CheckError` | DTO: `check` (`CheckName`), `message` — проверка, которая запускалась, но упала |
| `CheckName` | Enum: `Probe`, `Ssl`, `Whois`, `Dns`, `Content`, `Port`, `SecurityHeaders`, `RobotsTxt`, `Sitemap` |
| `CheckStatus` | Enum: `OK`, `WARNING`, `CRITICAL`, `UNKNOWN` |

## Безопасность

- HTTP-проверки принимают только `http` и `https` URL-ы.
- Входные хосты нормализуются и валидируются перед использованием.
- `SslCertificateService` читает peer-сертификаты в режиме мониторинга
  (`verify_peer: false`) — он не валидирует PKI-цепочку доверия.
- Пакет сам по себе не делает никаких сетевых запросов: он опирается на
  переданные пользователем PSR-18 клиенты и WHOIS-инстансы.

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

| Скрипт | Показывает | Нужна сеть? |
|---|---|---|
| `full-check.php` | Полная проверка домена через оркестратор `DomainMonitor` | Да |
| `http-probe.php` | HTTP-проба + проверка контента | Да |
| `ssl-whois-dns.php` | SSL, WHOIS и DNS | Да |
| `port.php` | TCP-проверка порта с произвольным хостом/портом | Да |
| `security-headers.php` | Проверка заголовков безопасности на живом URL | Да |
| `robots.php` | Загрузка `/robots.txt` и извлечение sitemap-ов | Да |
| `sitemap.php` | Загрузка sitemap и подсчёт URL-ов | Да |
| `report.php` | Сборка `DomainHealthReport` из DTO | Нет |

Запуск примеров:

```bash
php examples/port.php example.com 443
php examples/security-headers.php https://example.com
```

## Разработка

На хосте нет PHP/Composer — запускайте через Docker-образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
```

Интеграционные тесты (помечены `@coversNothing`) пропускаются, если не установлена
переменная `DOMAIN_MONITOR_NET=1`:

```bash
DOMAIN_MONITOR_NET=1 make test
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
