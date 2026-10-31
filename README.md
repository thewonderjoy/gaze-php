# thewonderjoy/gaze-php

Send values from any PHP code to the Gaze desktop app. Like `ray()`, but called `gaze()`.

Gaze is a fast debug window for PHP, Laravel and JavaScript (Windows first). This is its plain PHP client. Using Laravel? Install [`thewonderjoy/gaze-laravel`](https://packagist.org/packages/thewonderjoy/gaze-laravel) instead; it includes this package.

![The Gaze window with a PHP exception open: the message, and the code around the line that threw it](art/gaze-php.png)

Requires PHP 8.2+ with `curl` and `json`.

```bash
composer require --dev thewonderjoy/gaze-php
```

## Use

```php
gaze('Hello', $user, ['a' => 1]);              // one entry, three values
gaze($order)->red()->label('order')->large();  // colour, label and size
gaze()->table($rows, 'Rows');
gaze()->exception($e);
gaze_dd($payload);                             // send, then stop the script

$progress = gaze('Importing…');
foreach ($batches as $i => $batch) {
    import($batch);
    $progress->send("Imported {$i}");          // updates the same entry
}
```

One `gaze()` call is one entry in the app. Calling `->send()` again on the same instance replaces its content. Changing the colour or label later updates it in place.

## What you can call

- **Content:** `send`, `raw`, `pass`, `text`, `html`, `markdown`, `mermaid`, `json`, `xml`, `table`, `image`, `file`, `url`, `date`, `className`, `separator`, `custom($kind, $body)`
- **Look:** `green` `orange` `red` `purple` `blue` `gray`, `small` / `large`, `label`, `hide`, `expand(...)`, `expandAll`
- **Show or remove:** `remove`, `showIf` / `showWhen`, `removeIf` / `removeWhen`
- **App:** `newScreen`, `clearScreen`, `clearAll`, `screenColor`, `showApp`, `hideApp`, `notify`, `confetti`, `project`
- **Debugging:** `pause` (waits for Continue in the app), `measure` / `stopTime`, `trace`, `caller`, `exception($e, $meta)`, `phpinfo`
- **Control:** `count`, `limit`, `once`, `if`, `enable` / `disable`, `die`, `Gaze::rateLimiter()`, `Gaze::macro()`
- **Gaze only:** `id()`, `update($id, …)`, `append($id, …)`, `flush()`, `group($name, fn)`, and trace helpers `Gaze::startTrace()`, `Gaze::continueTrace($traceparent)`

The method names match `spatie/ray`. Not included: `windows()`, `theme()`, `ban()`, `charles()`. Not yet: `catch()`, `throwExceptions()`, `invade()`.

## Settings

```php
use TheWonderJoy\Gaze\Gaze;

Gaze::configure([
    'host' => '127.0.0.1',   // default: found automatically (see below)
    'port' => 23517,
    'enabled' => true,
    'project' => ['name' => 'shop', 'root' => '/var/www/html'],
]);
```

Or with environment variables: `GAZE_ENABLED`, `GAZE_HOST`, `GAZE_PORT`, `GAZE_TOKEN`, `GAZE_PROJECT`. `RAY_HOST` and `RAY_PORT` are read if the `GAZE_` ones are missing.

Without a host, the client tries `127.0.0.1`, then `host.docker.internal` (inside Docker or Sail), then the WSL2 host, and remembers which one answered.

When the app is closed, calls cost about 1–2 µs and nothing is sent. When disabled, `gaze()` does nothing at all. Errors never reach your code.

## Using it next to spatie/ray

This package never defines `ray()`, so it can sit next to `spatie/ray`. If you remove `spatie/ray` and want your old `ray()` calls to go to Gaze:

```php
TheWonderJoy\Gaze\Gaze::registerRayHelper(); // defines ray() and rd() only if they don't exist yet
```

## Remove debug calls before committing

```bash
vendor/bin/gaze clean --dry-run src app   # show what would be removed
vendor/bin/gaze clean                     # remove gaze(...) and gaze_dd(...) statements
```

Calls whose result is used (`$x = gaze()->pass($y)`) are listed and left alone.

## Develop

This package lives in the [thewonderjoy/gaze](https://github.com/thewonderjoy/gaze) monorepo under `packages/php`. The `thewonderjoy/gaze-php` repo is a read-only copy; send changes to the monorepo.

```bash
cd packages/php
composer install
vendor/bin/phpunit             # starts a fake app on 127.0.0.1:23610
php bench/per-call.php         # time per call
```

How the client works inside (transport, value conversion, measurements): [INTERNALS.md](INTERNALS.md).

## Release

From the monorepo, tag and push. Composer reads the version from the tag.

```bash
git tag php/v0.2.0
git push origin php/v0.2.0
```

CI copies `packages/php` to the `gaze-php` repo with tag `v0.2.0`, and Packagist picks it up. Full steps: `RELEASING.md` in the monorepo.

## License

MIT
