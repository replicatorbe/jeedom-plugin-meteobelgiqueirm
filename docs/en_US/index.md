# Belgian Weather RMI

Complete Belgian weather inside Jeedom, from Royal Meteorological Institute
data: observations, seven-day forecast, hourly forecast, short-term rain
forecast and official yellow, orange and red warnings.

> This plugin is not affiliated with, sponsored or endorsed by the Royal
> Meteorological Institute of Belgium. It reads the interface the official
> mobile application uses for itself. That interface is undocumented and may
> change without notice — it did three times in two years. The plugin is written
> to keep showing what it knows rather than to stop, but a breakage remains
> possible.

## Setup

No key, no account, no dependency to install.

1. **Add a municipality** and give it a name.
2. In the *Device* tab, type the first letters of the municipality and click the
   magnifier. All 565 Belgian municipalities ship with the plugin: the search
   works offline and accepts French as well as Dutch — "Elsene" finds Ixelles.
3. **Pick the municipality from the list.** The INS code shown next to the name
   tells apart municipalities with similar names.
4. **Save.** Commands are created and the weather is read straight away.

## Update rate

The plugin reads the weather **every ten minutes**, which is the rate at which
RMI publishes its observations and its radar sequence. After a failure the wait
doubles on each attempt, from ten minutes up to one hour.

## Commands

Current conditions: `temperature`, `condition`, `condition_id`, `pressure`,
`wind_speed`, `wind_gust`, `wind_direction`, `uv`, `sunrise`, `sunset`,
`data_age`.

Short-term rain: `rain_now` (mm/h), `rain_next` (minutes before rain, `-1` when
none is announced), `rain_soon`, `rain_hint` (RMI's own sentence).

Warnings: `warning_active`, `warning_level` (`-1` unknown, `0` none, `1` yellow,
`2` orange, `3` red), `warning_slug`, `warning_slugs` (sorted), `warning_label`,
`warning_text`, `warning_end`, plus `next_warning_*` for what is announced but
not yet in force.

Forecast: `temperature_1_min` through `temperature_7_min` and the matching
`_max` — note the index sits **in the middle** — plus `condition_1..7`,
`condition_id_1..4`, `rain_chance_1..3`, the hourly `*_h1..h3`, and
`bulletin_0` / `bulletin_1` carrying RMI's written bulletin.

Same day: forecasts `_1` to `_7` start **tomorrow**; today has
`temperature_0_min`, `temperature_0_max`, `condition_0`, `condition_id_0` and
`rain_chance_0`, read from RMI's *daytime* block. RMI drops today's minimum
around midday (observed: absent at 12:44); before noon the lowest remaining
hourly forecast replaces it, afterwards it is unknown and the command keeps its
last value. When the daytime block is gone (evening), the maximum and chance of
rain fall back on the remaining hours.

`icon_mdi` carries a Material Design Icons name for the **current** conditions
(`mdi:weather-sunny`, `mdi:weather-night`, `mdi:weather-rainy`…), for a display
outside Jeedom such as a TV. Unknown codes give `mdi:weather-cloudy`; dry
weather with a mean wind above 50 km/h gives `mdi:weather-windy`.

## Morning bulletin

Disabled by default. Configured in the *Device* tab, next to the warning
notifications: a sending time (`bulletin_time`, `HH:MM`, default `06:30`),
weekdays (`bulletin_days`, ISO digits, empty = every day), an optional Jeedom
condition (`bulletin_condition`), a list of actions (`bulletin_cmds`, same
selector as warnings), a title (`bulletin_title`, default "Météo du jour") and a
message (`bulletin_message`, default `#min#°C - #max#°C | #conseils#`). Tags:
`#min#`, `#max#`, `#conditions#`, `#conseils#` (advice), `#bulletin#`,
`#commune#`, `#vent#` (km/h), `#pluie#` (%).

It is sent **once a day**: the date is remembered in the device cache. If Jeedom
was down at the chosen time, it is still sent within two hours, never later.
A false condition skips the day; a condition that cannot be computed is logged
and the bulletin is **not** sent. The *Test* button sends today's real bulletin
right away and shows what the condition is currently worth. Advice follows the
Home Assistant automation it replaces: umbrella (rain, showers, thunder codes),
frost (min < 0), warm jacket (min < 5), heat (max > 25), wind (> 40 km/h), snow,
fog; "Journée agréable en perspective" when none applies.

The tile also carries an **hour-by-hour band** covering the rest of the day —
hour, weather, temperature, and a bar whose height is the chance of rain. In the
evening, when fewer than six hours remain, it spills over into the night and the
next morning rather than shrinking to two useless columns; a vertical rule marks
midnight. Widget options `days`, `rain`, `range` and `hours` set to `0` hide the
three-day strip, the rain line, the day's extremes and the hourly band.

**Humidity is not provided**: it does not exist anywhere in RMI's data, and a
command showing 0% would be a lie.

> **Announced is not in force.** RMI publishes warnings ahead of time, sometimes
> twelve hours early. `warning_active` and `warning_level` only describe what is
> currently in force; what is coming has its own commands.

## When RMI is unreachable

The plugin **never erases what it knows**. The last known values stay on screen,
stamped with their real reading time: the tile shows "data from 11:40" in red
past one hour, `data_age` climbs, and after 45 minutes Jeedom itself marks the
device as timed out and posts a message. Stale data that looks fresh is more
dangerous than a plain error.
