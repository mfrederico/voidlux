<?php

declare(strict_types=1);

namespace VoidLux\Swarm\Capabilities\Plugins;

use VoidLux\Swarm\Capabilities\PluginInterface;
use VoidLux\Swarm\Model\{AgentModel, TaskModel};

/**
 * Weather information plugin.
 *
 * Provides context for fetching weather data via wttr.in and other APIs.
 * Uses native curl/wget for HTTP requests.
 *
 * Capabilities: weather, forecast, meteorology
 */
class WeatherPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'weather';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Weather forecasts and conditions via wttr.in and weather APIs';
    }

    public function getCapabilities(): array
    {
        return ['weather', 'forecast', 'meteorology'];
    }

    public function getRequirements(): array
    {
        return ['curl'];
    }

    public function checkAvailability(): bool
    {
        exec('which curl 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    public function install(): array
    {
        exec('which curl 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            exec('apt-get update && apt-get install -y curl 2>&1', $output, $installCode);
            if ($installCode !== 0) {
                return [
                    'success' => false,
                    'message' => 'Failed to install curl: ' . implode("\n", $output),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Weather plugin ready (curl installed)',
        ];
    }

    public function injectPromptContext(TaskModel $task, AgentModel $agent): string
    {
        return <<<'CONTEXT'
## Weather Information Available

You can fetch weather data using wttr.in (amazing terminal weather) or weather APIs. Use your native **Bash** tool:

### wttr.in - Terminal Weather (Recommended)

Beautiful, feature-rich weather reports:

```bash
# Current weather for location
curl wttr.in/London
curl wttr.in/NewYork
curl wttr.in/Tokyo
curl "wttr.in/San Francisco"  # Quote if space in name

# Weather at your IP location
curl wttr.in

# Use coordinates (latitude,longitude)
curl "wttr.in/40.7128,-74.0060"  # NYC

# Concise one-line output
curl "wttr.in/London?format=3"
# Output: London: ⛅️ +15°C

# Just current conditions
curl "wttr.in/London?format=1"
# Output: ⛅️ +15°C

# Custom format (use format codes)
curl "wttr.in/London?format=%l:+%c+%t+%w"
# Output: London: ⛅️ +15°C 10km/h↗

# PNG image (for embedding)
curl "wttr.in/London.png" -o weather.png

# Transparent PNG
curl "wttr.in/London_tqp0.png" -o weather-transparent.png

# 3-day forecast
curl "wttr.in/London?n"

# No ANSI colors (for parsing)
curl "wttr.in/London?T"

# JSON output (for programmatic use)
curl "wttr.in/London?format=j1"
```

### Format Codes for Custom Output
- `%c` - Weather condition (emoji)
- `%C` - Weather condition (text)
- `%t` - Temperature (°C)
- `%f` - Feels like temperature
- `%w` - Wind speed and direction
- `%h` - Humidity (%)
- `%p` - Precipitation (mm)
- `%P` - Pressure (hPa)
- `%m` - Moon phase 🌕🌖🌗
- `%l` - Location name
- `%S` - Sunrise time
- `%s` - Sunset time

### JSON Parsing Example
```bash
# Get temperature as JSON
curl -s "wttr.in/London?format=j1" | jq '.current_condition[0].temp_C'

# Get condition description
curl -s "wttr.in/London?format=j1" | jq -r '.current_condition[0].weatherDesc[0].value'

# Full current conditions
curl -s "wttr.in/London?format=j1" | jq '.current_condition[0]'
```

### OpenWeatherMap API (Alternative)

If you have an API key:

```bash
# Current weather
curl "https://api.openweathermap.org/data/2.5/weather?q=London&appid=YOUR_API_KEY&units=metric"

# 5-day forecast
curl "https://api.openweathermap.org/data/2.5/forecast?q=London&appid=YOUR_API_KEY&units=metric"

# Parse with jq
curl -s "https://api.openweathermap.org/data/2.5/weather?q=London&appid=KEY&units=metric" \
  | jq '{temp: .main.temp, condition: .weather[0].description, humidity: .main.humidity}'
```

### PHP Implementation Example
```php
<?php
// Get weather as array
function getWeather($location) {
    $url = "wttr.in/{$location}?format=j1";
    $json = file_get_contents($url);
    return json_decode($json, true);
}

$weather = getWeather('London');
$temp = $weather['current_condition'][0]['temp_C'];
$condition = $weather['current_condition'][0]['weatherDesc'][0]['value'];
echo "London: {$condition}, {$temp}°C\n";
?>
```

### Tips
- wttr.in works without API keys (rate limit: ~1000/day)
- Add `?lang=de` for German, `?lang=ru` for Russian, etc.
- Use `?m` for metric, `?u` for US units (default is metric)
- Cache results to avoid rate limits: `curl -s wttr.in/London > /tmp/weather.cache`
- ASCII art weather: just `curl wttr.in/London` (no options)
- Moon phase: `curl wttr.in/moon` or `curl wttr.in/Moon@2024-12-25`

### Location Formats Supported
- City names: `London`, `New York`, `San Francisco`
- Airports: `SFO`, `LAX`, `JFK`
- Domain names: `@github.com`, `@google.com`
- Area codes: `~12345` (US postal codes)
- GPS coordinates: `48.8567,2.3508` (Paris)
- IP addresses: `@8.8.8.8` (location by IP)
- Special: `Moon` (moon phase calendar)

CONTEXT;
    }

    public function onEnable(string $agentId): void
    {
        // No state to initialize
    }

    public function onDisable(string $agentId): void
    {
        // No cleanup needed
    }
}
