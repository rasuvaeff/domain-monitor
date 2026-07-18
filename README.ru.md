# Расуваефф/домен-монитор
[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/domain-monitor/v)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/domain-monitor/downloads)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![Build](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/domain-monitor/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/domain-monitor/php)](https://packagist.org/packages/rasuvaeff/domain-monitor)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
Модульный набор инструментов для мониторинга домена для PHP 8.3+. Нулевая платформа, PSR-совместимая, с небольшими неизменяемыми DTO и специализированными сервисами без сохранения состояния. Каждая шашка делает одно — вы составляете их по мере необходимости.

 **Проверка:** HTTP-зонд · SSL-сертификаты · WHOIS · DNS · TCP-порты · заголовки безопасности · `robots.txt` · карты сайта.

 **Не включает:** планирование, сохранение, кэширование или асинхронные средства выполнения. В пакет входят строительные блоки и оркестратор DomainMonitor; ваше приложение обеспечивает рабочий процесс.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `ext-openssl`, `ext-simplexml`
 - Клиент PSR-18 и фабрика запросов PSR-17 для проверок на основе HTTP
 - `io-developer/php-whois` (извлекает `ext-curl`, `ext-mbstring`)
 - `ext-intl` не является обязательным (нормализация IDN) только)
 - `ext-sockets` не является обязательным (только разрешение DNS)

## Установка
```bash
composer require rasuvaeff/domain-monitor
```
Для проверок HTTP вам также понадобится реализация PSR-18/PSR-17:

```bash
composer require symfony/http-client nyholm/psr7
```
## Быстрый старт: полная проверка домена
### Самый простой: завод.
`DomainMonitor::create()` передает каждую проверку от одного клиента PSR-18 + фабрики PSR-17 (WHOIS необязательно):

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
Для детального контроля над тем, какие проверки выполняются, используйте `DomainMonitorBuilder`:

```php
use Rasuvaeff\DomainMonitor\DomainMonitorBuilder;

$monitor = DomainMonitorBuilder::create()
    ->withHttp(client: new Psr18Client(), requestFactory: new Psr17Factory())
    ->withWhois(Factory::get()->createWhois())
    ->withoutPort()
    ->build();
```
### Использование оркестратора (рекомендуется)
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
Службы не являются обязательными — укажите null (или опустите), чтобы отключить проверку. Оркестратор повторно использует один ответ HTTP для проверки + заголовков безопасности + проверки содержимого. Неудачные проверки перехватываются, протоколируются через PSR-3 и исключаются из отчета. @@ЛИНИЯ@@
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
## Чтение отчета
`getStatus()` — это совокупность (худшая из всех проверок). Чтобы узнать *почему*, повторите результаты каждой проверки — каждый из них содержит «CheckName», «CheckStatus» и удобочитаемую «причину»:

```php
foreach ($report->getChecks() as $result) {
    printf("%-16s %-8s %s\n", $result->check->value, $result->status->value, $result->reason);
}
// probe            ok       HTTP 200
// ssl              ok       Certificate valid, expires in 61 day(s)
// whois            warning  Domain expires in 12 day(s)

$ssl = $report->getCheck(name: CheckName::Ssl); // ?CheckResult
```
### Ошибки против отключенных проверок
Проверка, которая была **не настроена**, имеет значение null. Проверка, которая **выполнялась, но выбрасывалась**, записывается отдельно — она отображается в `getChecks()` как `UNKNOWN` (никогда не раздувает агрегат) и в `getErrors()`:

```php
if ($report->hasErrors()) {
    foreach ($report->getErrors() as $error) {
        // CheckError { check: CheckName, message: string }
        echo "{$error->check->value}: {$error->message}\n";
    }
}
```
Считайте `getStatus() === CheckStatus::OK` вместе с `hasErrors() === true` как "ОК, но неполно". @@ЛИНИЯ@@
### Пороги
По умолчанию SSL становится «КРИТИЧЕСКИМ» только по истечении срока действия, а WHOIS предупреждает в течение 30 дней. Включите параметр «Срок действия SSL скоро истечет = предупреждение» (и настройте окно WHOIS) с помощью ReportThresholds:

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
`ReportThresholds::default()` точно воспроизводит поведение до версии 1.2.0. @@ЛИНИЯ@@
### Сериализация
Каждый результат DTO реализует `JsonSerializable`, поэтому весь отчет кодируется за один вызов: даты как ISO-8601, перечисления как их значения, отключенные проверки как `null`:

```php
$json = json_encode($report, JSON_THROW_ON_ERROR);
```
Массив «checks» представляет собой оцененный снимок (замороженные строки «причины»); вложенные необработанные DTO (`ssl.validUntil`, `whois.expirationDate`) остаются абсолютными, поэтому сохраненный большой двоичный объект является достоверной записью.

## Обнаружение изменений статуса

`DomainHealthReport` — это один снимок. Чтобы алертить только при *изменении*, храните предыдущий снимок (хранение — на стороне приложения) и сравнивайте его с текущим через `ReportComparator` — stateless-хелпер, возвращающий по одному `StatusTransition` на каждую изменившуюся проверку:

```php
use Rasuvaeff\DomainMonitor\ReportComparator;
use Rasuvaeff\DomainMonitor\TransitionKind;

$comparator = new ReportComparator();

$previous = $storage->latest(host: 'example.com'); // ваше хранилище, при первом запуске может быть null
$current = $monitor->check(host: 'example.com');

$diff = $comparator->compare(previous: $previous ?? $current, current: $current);

if ($diff->hasChanges()) {
    foreach ($diff->getTransitions() as $t) {
        printf("%s: %s -> %s (%s)\n", $t->check->value, $t->from?->value ?? '—', $t->to?->value ?? '—', $t->kind->value);
    }
}

$storage->save(host: 'example.com', report: $current); // для следующего запуска
```

`compare()` возвращает обёртку `ReportDiff` (`hasChanges()`, `getTransitions()`, `worstTransition()`); `diff()` — сырой `list<StatusTransition>`. Каждый transition несёт `TransitionKind`:

| Kind | Значение |
|---|---|
| `Appeared` | Проверки не было, теперь есть (`from` = `null`) |
| `Disappeared` | Проверка была, теперь отсутствует (`to` = `null`) |
| `Degraded` | Статус ухудшился (`ok → critical`) |
| `Recovered` | Статус улучшился (`critical → ok`) |
| `Changed` | Статус изменился в/из `UNKNOWN`, где severity несравнима |

`ReportComparator` сравнивает по `CheckName` и детерминирован (`diff($r, $r)` всегда `[]`). Он ничего не знает про расписание, хранение и доставку — передача transitions в webhooks или realtime-канал остаётся задачей приложения. Готовый pipeline (расписание, история, webhook + Centrifugo алерты, status-страница) — в `rasuvaeff/monitor-dashboard`.

## Услуги
### HTTP-зондирование
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
`timeoutSeconds` используется **только с максимальной эффективностью** — PSR-18 не имеет стандартного API таймаута. Такие клиенты, как Symfony, чтят это; клиенты, такие как сырой Guzzle, могут этого не делать. @@ЛИНИЯ@@
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
Примечание. Проверка SSL считывает сертификат узла **без проверки цепочки доверия** — это инструмент мониторинга, а не средство проверки PKI. @@ЛИНИЯ@@
### WHOIS
```php
use Iodev\Whois\Factory;
use Rasuvaeff\DomainMonitor\WhoisService;

$info = (new WhoisService(whois: Factory::get()->createWhois()))
    ->check(host: 'example.com');

// TldInfo { domain, ?registrar, ?expirationDate, states }
echo $info->daysUntilExpiry(); // null if expirationDate missing
```
Резервный вариант: в случае сбоя www.example.com служба автоматически повторяет попытку с example.com. @@ЛИНИЯ@@
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
### Карта сайта
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
### Создать отчет
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
## Полная ссылка на API
| Класс | Что он делает |
 |---|---|
 | `DomainMonitor` | Оркестратор: запускает все настроенные службы, повторно использует HTTP-ответ для проверки + заголовки безопасности + контент → `DomainHealthReport`; Фабрика `create()` + реализует `DomainMonitorInterface` |
 | `DomainMonitorInterface` | Контракт для `DomainMonitor` — макетируем/украшаем его |
 | `DomainMonitorBuilder` | Свободный, детализированный состав оркестратора (`withHttp`, `withWhois`, `withoutPort`, …) |
 | `DomainMonitorOptions` | VO для оркестратора: порт, тайм-аут, метод, userAgent, ожидаемыйОрг, ожидаемыйстатус, требуемыйтекст, запрещенныйтекст, пороговые значения |
 | `Пороги отчета` | VO: окно предупреждения об истечении срока действия SSL (`sslWarnDays`) + окно предупреждения WHOIS (`whoisWarnDays`); `default()` / `strict()` |
 | `HostNormalizer` | Нормализовать хосты/URL-адреса (строчные буквы, схема разделения/порт/путь, необязательный IDN) |
 | `HttpProbeService` | PSR-18 GET/HEAD зонд с измеренным временем → `ProbeResult`; `probeWithResponse()` для повторного использования ответа |
 | `HttpProbeWithResponse` | DTO: `ProbeResult` + `ResponseInterface` (для повторного использования ответа) |
 | `HttpProbeOptions` | Настройка метода, заголовков, тайм-аута и пользовательского агента для HTTP-зондов |
 | `ProbeResult` | DTO: `статус`, `totalTime` |
 | `SslCertificateService` | Чтение удаленного сертификата SSL; дополнительный организационный фильтр → `SslCertificate` |
 | `SslCertificate` | DTO: `validFrom`, `validUntil`, `subjectCn`, `issuer` + помощники по истечении срока действия |
 | `WhoisService` | Загрузить и сопоставить данные поставщика WHOIS → `TldInfo` |
 | `ТлдИнфо` | DTO: `домен`, `?регистратор`, `?expirationDate`, `состояния` |
 | `ДнсСервис` | оболочка `dns_get_record()` → `DnsRecords` |
 | `DnsRecords` | DTO: `a`, `aaaa`, `mx`, `ns`, `txt`, `cname` |
 | `ПортСервис` | Доступность TCP через `stream_socket_client()` → `PortCheck` |
 | `ПортЧек` | DTO: `status`, `host`, `port`, `connectTime`, `?error` |
 | `SecurityHeadersService` | Проверьте HSTS/CSP/XFO/XCTO в ответе PSR-7 → `SecurityHeadersCheck` |
 | `SecurityHeadersCheck` | DTO: флаги для каждого заголовка + списки присутствия/отсутствия |
 | `RobotsTxtService` | Получить `/robots.txt` + извлечь подсказки карты сайта → `RobotsTxtCheck` |
 | `РоботыTxtCheck` | DTO: `exists`, `httpStatus`, `sitemaps[]` |
 | `СайтмапСервис` | Получить карту сайта + подсчитать записи `<url>` → `SitemapCheck` |
 | `Проверка карты сайта` | DTO: `exists`, `httpStatus`, `urlCount` |
 | `HttpContentCheckService` | Код состояния + проверка обязательного/запрещенного ключевого слова → `HttpContentCheck`; `checkFromResponse()` для повторного использования ответа |
 | `HttpContentCheck` | DTO: `status`, `httpStatus`, `?finalUrl`, текстовые флаги |
 | `DomainHealthReport` | Составной DTO для всех результатов проверки; `getStatus()` агрегат, `getChecks()`/`getCheck()` для каждой проверки, `getErrors()`/`hasErrors()`, `JsonSerializable` |
| `ПроверитьРезультат` | DTO: `проверка` (`CheckName`), `статус` (`CheckStatus`), `причина` (читабельно для человека) |
 | `CheckError` | DTO: `check` (`CheckName`), `message` — проверка, которая выполнялась, но выдала ошибку |
 | `Проверочное имя` | Перечисление: `Probe`, `Ssl`, `Whois`, `Dns`, `Content`, `Port`, `SecurityHeaders`, `RobotsTxt`, `Sitemap` |
 | `Проверить статус` | Перечисление: `ОК`, `ПРЕДУПРЕЖДЕНИЕ`, `КРИТИЧНО`, `НЕИЗВЕСТНО` | @@ЛИНИЯ@@
## Безопасность
– HTTP-проверки принимают только URL-адреса `http` и `https`.
 — входные данные хоста нормализуются и проверяются перед использованием.
 — `SslCertificateService` считывает одноранговые сертификаты в режиме мониторинга (`verify_peer: false`) — он не проверяет цепочку доверия PKI.
 - Пакет сам по себе не выполняет никаких сетевых запросов: он опирается на предоставляемых пользователем клиентов PSR-18 и экземпляры WHOIS. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев.

 | Скрипт | Шоу | Сеть? |
 |---|---|---|
 | `full-check.php` | Полная проверка домена через оркестратор DomainMonitor | Да |
 | `http-probe.php` | HTTP-зонд + проверка содержимого | Да |
 | `ssl-whois-dns.php` | SSL, WHOIS и DNS | Да |
 | `порт.php` | Проверка TCP-порта с пользовательским хостом/портом | Да |
 | `security-headers.php` | Проверка заголовков безопасности на активном URL-адресе | Да |
 | `robots.php` | Загрузите `/robots.txt` и извлеките карты сайта | Да |
 | `sitemap.php` | Получить карту сайта и подсчитать URL-адреса | Да |
 | `report.php` | Создайте «DomainHealthReport» из DTO | Нет |

 Запустите примеры:

```bash
php examples/port.php example.com 443
php examples/security-headers.php https://example.com
```
## Разработка
На хосте нет PHP/Composer — запустите в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```
Или с помощью Make:

```bash
make install
make build
make cs-fix
make test
```
Интеграционные тесты (с пометкой `@coversNothing`) пропускаются, если не установлено `DOMAIN_MONITOR_NET=1`:

```bash
DOMAIN_MONITOR_NET=1 make test
```
## Лицензия
[BSD-3-пункт](LICENSE.md)
