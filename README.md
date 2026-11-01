# thewonderjoy/gaze-php

Send values from any PHP code to the Gaze desktop app with `gaze()`. Each one shows up with the file and line that sent it.

Gaze is a desktop debug window for PHP, Laravel and JavaScript apps, on Mac, Windows and Linux. This package is the plain PHP client. On Laravel, install [`thewonderjoy/gaze-laravel`](https://packagist.org/packages/thewonderjoy/gaze-laravel) instead. It includes this package.

**Get the app:** download Gaze from [thewonderjoy.com/products/gaze](https://thewonderjoy.com/products/gaze).

![The Gaze window with a PHP object sent with gaze() open in the inspector, next to a script paused on gaze()->pause() and an exception from the same app, shown half in the light theme and half in the dark theme](https://raw.githubusercontent.com/thewonderjoy/gaze-php/main/art/gaze-php.png)

## Install

```bash
composer require thewonderjoy/gaze-php
```

It needs PHP 8.2+ with the `curl` and `json` extensions. Open the [Gaze app](https://thewonderjoy.com/products/gaze) and try it:

```bash
php -r "require 'vendor/autoload.php'; gaze('Hello from PHP');"
```

### Normal or `--dev`?

Install it as a normal dependency, as above. `--dev` keeps the package off your servers, but then a call you forgot to remove breaks there:

| | `composer require` | `composer require --dev` |
|---|---|---|
| On a production server | Installed but off. `gaze()` returns a no-op, and nothing else from the package loads. | Not installed (`composer install --no-dev`) |
| A `gaze()` or `gaze_dd()` you forgot to remove | Does nothing. `gaze_dd()` doesn't exit. | Fatal error: `Call to undefined function gaze()` |
| Before you deploy | Nothing to do | Run `vendor/bin/gaze clean` |

Writing a library? Use `--dev`, and run `vendor/bin/gaze clean` before each release. The people who install your library won't have Gaze.

### In production

Gaze is off when `APP_ENV` is `production` or `prod`. Plain PHP apps often don't set `APP_ENV`, and then Gaze stays on. Set `APP_ENV=production` or `GAZE_ENABLED=false` on your servers. To use it in production for a while, set `GAZE_ENABLED=true`.

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

Each `gaze()` call makes one entry in the app. Calling `->send()` again on the same object replaces what the entry shows, and changing its colour or label later updates it in place.

## Methods

Methods return the same object, so calls chain: `gaze($x)->red()->label('x')`.

**Send something**

| Method | What it shows |
|---|---|
| `gaze(...$values)`, `send(...$values)` | One or more values in one entry. A closure is called, and its result is sent. |
| `raw(...$values)` | Like `send()`, but a closure is shown instead of called. |
| `pass($value)` | Sends `$value` and returns it: `return gaze()->pass($total);` |
| `text($text)` | Plain text. |
| `html($html)` | Rendered HTML, in a sandbox where scripts don't run. |
| `markdown($markdown)` | Rendered Markdown. |
| `mermaid($source)` | A Mermaid diagram. |
| `json(...$values)`, `toJson(...$values)` | A JSON tree. JSON strings are decoded first. |
| `xml($xml)` | Indented XML. |
| `table($rows, $label = 'Table')` | A key/value table, or rows and columns for a list of arrays. |
| `image($pathOrUrl, $alt = null)` | An image from a URL or a local file. |
| `file($path)` | A file's contents, up to the first 1 MB. |
| `url($url, $label = '')`, `link(...)` | A clickable link. |
| `date($date, $format = 'Y-m-d H:i:s')`, `carbon($date)` | A date with its timestamp and timezone. |
| `className($object)` | The class of an object. |
| `separator()` | A divider line. |
| `exception($e, $meta = [])` | An exception with its stack trace. `$meta` is shown as context. |
| `phpinfo(...$keys)` | The PHP version, main ini settings and extensions, or only the ini keys you name. |
| `custom($kind, $body)` | An entry of any kind. Framework packages use it. |

**Update an entry**

| Method | What it does |
|---|---|
| `id()` | The entry's id. |
| `update($id, ...$values)` | Replaces what an earlier entry shows. |
| `append($id, ...$values)` | Adds to an earlier entry. |

**Change how it looks**

| Method | What it does |
|---|---|
| `green()` `orange()` `red()` `purple()` `blue()` `gray()`, `color($name)` | Colours the entry. |
| `small()`, `large()`, `size($size)` | Text size. |
| `label($text)` | Adds a label. |
| `hide()` | Shows the entry collapsed. |
| `expand()`, `expand(3)`, `expand('user.roles')` | Opens the value tree by levels or by key path. |
| `expandAll()` | Opens the whole tree. |
| `remove()` | Removes the entry from the app. |
| `showIf($condition)`, `showWhen(...)` | Keeps the entry only if the condition is true. |
| `removeIf($condition)`, `removeWhen(...)` | Removes the entry if the condition is true. |

**Decide when to send**

| Method | What it does |
|---|---|
| `if($condition)` | Later calls on this object only send when the condition is true. |
| `if($condition, fn ($gaze) => ...)` | Runs the callback when the condition is true. |
| `once(...$values)` | Sends only the first time this line runs. |
| `limit($n)` | Sends at most `$n` times from this line. |
| `count($name = null)` | Counts how often this line, or this name, runs, in one entry. |
| `counterValue($name)`, `clearCounters()` | Reads or resets the counters. |
| `Gaze::rateLimiter()->max($n)->perSecond($n)` | Caps how much is sent in total, or per second. `->clear()` resets it. |
| `enable()`, `disable()`, `enabled()`, `disabled()` | Turns sending on or off for this process, or checks it. |
| `Gaze::macro($name, fn)` | Adds your own method. |

**Debug**

| Method | What it does |
|---|---|
| `pause()` | Stops the script until you press Continue in the app. |
| `measure($name = 'default')` | A stopwatch. The first call starts it, later calls show the total and lap time. |
| `measure(fn () => ...)` | Times a closure. |
| `stopTime($name = '')` | Forgets one stopwatch, or all of them. |
| `trace()`, `backtrace()` | The stack trace from this line. |
| `caller()` | The function that called the current one. |
| `group($name, fn () => ...)` | Groups the entries made inside the closure and returns its result. |
| `group($name)` ... `groupEnd()` | The same, without a closure. |
| `flush()` | Sends everything queued now and waits for the app, 2 s at most. |
| `die($status = '')` | Sends everything, waits until the app has it, then stops the script. |
| `gaze_dd(...$values)` | Sends the values, then stops the script with exit status 1, like `dd()`. |

**Control the app**

| Method | What it does |
|---|---|
| `newScreen($name = '')` | Starts a new, empty screen. |
| `clearScreen()` | Clears the current screen. |
| `clearAll()` | Clears all screens. |
| `screenColor($color)` | Colours the screen. |
| `showApp()`, `hideApp()` | Brings the window to the front, or hides it. |
| `notify($text)` | A desktop notification. |
| `confetti()` | Confetti. |
| `project($name)` | The project name for the entries that follow. |

**Traces**

A trace is one unit of work, such as a request, a job or a command. The app groups its entries together.

| Method | What it does |
|---|---|
| `Gaze::startTrace($name, $kind)` | Starts a trace (`http`, `job`, `command`, `test`, ...). Later entries belong to it. |
| `Gaze::continueTrace($traceparent, $name)` | Starts the trace of a request that came with a W3C `traceparent` header. The app links it to the call that caused it. |
| `Gaze::traceparent()` | The `traceparent` value to send with an outgoing call, so the app links the other side's trace to this one. |

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

Or use environment variables: `GAZE_ENABLED`, `GAZE_HOST`, `GAZE_PORT`, `GAZE_TOKEN` and `GAZE_PROJECT`.

`enabled` is on unless `APP_ENV` is `production` or `prod`. `GAZE_ENABLED` and `Gaze::configure()` override that. `gaze()->disable()` only pauses sending until you call `enable()`. When configuration or the environment turned Gaze off, `enable()` can't turn it back on.

Without a host, the client tries `127.0.0.1`, then `host.docker.internal` inside Docker or Sail, then the WSL2 host. It remembers which one answered.

While the app is closed, a call costs about 1–2 µs and nothing is sent. While Gaze is off, `gaze()` does nothing at all. Errors inside Gaze never reach your code.

## Remove debug calls

```bash
vendor/bin/gaze clean --dry-run src app   # show what would be removed
vendor/bin/gaze clean                     # remove gaze(...) and gaze_dd(...) statements
```

A call whose result your code uses, like `$x = gaze()->pass($y)`, is listed and left alone.

## Ray compatibility

The method names match `spatie/ray`. To move a project over, replace `ray(` with `gaze(`.

The Gaze app accepts Ray's messages too, so `spatie/ray` can keep sending to it while you switch. This package never defines `ray()`, so the two packages can be installed side by side. Once you remove `spatie/ray`, you can send your old `ray()` calls to Gaze:

```php
TheWonderJoy\Gaze\Gaze::registerRayHelper(); // defines ray() and rd() only if they don't exist yet
```

`RAY_HOST` and `RAY_PORT` are read when `GAZE_HOST` and `GAZE_PORT` aren't set.

Not included from spatie/ray: `windows()` and `theme()`, which ask the Ray app for its windows and theme, plus `ban()` and `charles()`.

Not yet included: `catch()`, `throwExceptions()`, `invade()`, `yellow()`, `sendCustom()` (use `custom()`), and the `screenGreen()`-style shortcuts (use `screenColor('green')`).

## License

MIT
