# Reddit Giveaway Extractor v3.5.1

## New in v3.5.1

The `[GAMES] ... [/GAMES]` parser now supports game lines that include a trailing Steam or other HTTP/HTTPS URL:

```text
Atomic Heart: https://store.steampowered.com/app/668580/Atomic_Heart/
Keylocker | Turn Based Cyberpunk Action: https://store.steampowered.com/app/1325040/...
Pocket Mirror ~ GoldenerTraum: https://store.steampowered.com/app/1899060/...
```

The URL and the colon immediately before it are removed before the title is sent to the picker.

Characters that are part of the real title, including `|` and `~`, are preserved.

Markdown escape backslashes used in the Reddit source are not part of the rendered title on Old Reddit, so they do not become part of the extracted game name.

All v3.5 behavior remains:
- Auto-load all Old Reddit comments.
- Export one giveaway-package JSON file.
- Open the local Giveaway Picker directly with the package already loaded.
- Internally scrolling extractor panel.
