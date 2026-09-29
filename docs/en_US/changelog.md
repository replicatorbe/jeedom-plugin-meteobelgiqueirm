# Changelog

## 0.1

First release.

- One Belgian municipality per device, picked from the 565-municipality list
  shipped with the plugin: the search works offline, in French and in Dutch.
- Observations, seven-day forecast, hourly forecast H+1 to H+3.
- RMI's written bulletin for today and tomorrow.
- Short-term rain forecast, numeric, with RMI's own sentence.
- Official yellow, orange and red warnings, separating what is in force from
  what is announced.
- A single tile gathering the essentials, identical on mobile.
- Automatic notification on weather warnings, with a configurable threshold.
- Morning bulletin: every day at the chosen time, the day's minimum, maximum
  and advice sent to the actions of your choice, once a day, with a catch-up
  window of two hours, weekdays, an optional condition and a test button.
  Disabled by default.
- Same-day commands: today's min and max temperature, conditions, condition
  code and chance of rain.
- *Icon* command: Material Design Icons name of the current conditions, for a
  display outside Jeedom.
- Values are published with their real reading time: stale data is visible, and
  Jeedom marks the device as timed out after 45 minutes.
