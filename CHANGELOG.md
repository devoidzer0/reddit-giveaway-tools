# Changelog

## GitHub package 1.0

### Extractor v3.5
- Loads Old Reddit comment expansion controls directly.
- Parses the authoritative game list from the original post.
- Supports `[GAMES] ... [/GAMES]` blocks.
- Exports a single giveaway-package JSON file.
- Can open the local Giveaway Picker directly with the package already loaded.
- Uses an internally scrolling status window so controls remain reachable in shorter browser windows.

### Picker v2.9.1
- Imports the extractor's giveaway-package JSON.
- Accepts direct package submission from Extractor v3.5.
- Parses ranked game preferences and provides manual review for ambiguous cases.
- Supports multiple games from one review fragment.
- Randomizes the full eligible entrant pool once.
- Assigns each entrant their highest-ranked still-available game.
- Retains the complete randomized entrant order.
- Exports public results as standalone HTML.
