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
