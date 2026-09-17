# Reddit Giveaway Tools

A two-part, local-first toolkit for running ranked-choice game giveaways from Reddit.

Current components:

- **Reddit Giveaway Extractor v3.5**
- **Reddit Giveaway Picker v2.9.1**

The intended workflow is:

**Old Reddit thread → Extractor → Giveaway Picker → Review ambiguous choices → Randomize entrants → Assign games → Export public results HTML**

## What the tools do

### 1. Reddit Giveaway Extractor

The extractor is a bookmarklet designed for **Old Reddit**. It:

- loads all available comments without repeatedly scrolling the page;
- identifies top-level comments used as giveaway entries;
- reads the giveaway game list from the original post;
- supports a preferred `[GAMES] ... [/GAMES]` block for future giveaways;
- keeps compatibility with older completed giveaways that contain winner/TBD annotations;
- exports a single JSON giveaway package containing the verified game list, top-level comments, and thread metadata;
- can open the local Giveaway Picker directly with that package already loaded.

No Reddit API or OAuth credentials are used.

### 2. Reddit Giveaway Picker

The picker is a local PHP app. It:

- imports the extractor's single JSON giveaway package;
- parses each entrant's ranked game choices;
- automatically handles common formatting variations, abbreviations, misspellings, and run-on lists;
- flags genuinely uncertain choices for manual review;
- allows a review line to be resolved into multiple ranked games when necessary;
- randomizes the **entire eligible entrant pool once**;
- walks through that fixed randomized order and awards each person their highest-ranked game still available;
- continues until every game has been awarded or no eligible choices remain;
- keeps the complete randomized entrant order for auditing;
- exports a standalone public HTML results page.

Listing more acceptable games can improve an entrant's chance of winning *something*, because the picker can move to their next ranked choice if an earlier choice has already been taken.

## Repository layout

```text
reddit-giveaway-tools/
├── README.md
├── CHANGELOG.md
├── .gitignore
├── extractor/
│   ├── README.md
│   ├── bookmarklet.html
│   └── extractor.js
├── picker/
│   ├── README.md
│   ├── index.php
│   └── sample-comments.tsv
└── docs/
    └── GIVEAWAY_POST_FORMAT.md
```

## Quick start

### Extractor

1. Open `extractor/bookmarklet.html` in your browser.
2. Drag **Reddit → Giveaway Package** to the bookmarks bar.
3. Open the giveaway thread on `old.reddit.com`.
4. Run the bookmarklet.
5. Verify the game list.
6. Click **Auto-load ALL comments (Old Reddit)**.
7. Either:
   - click **Open in GIVEAWAY PICKER**, or
   - download the JSON giveaway package for later.

### Picker

The direct-open workflow expects the picker to be available at:

```text
http://localhost/reddit-giveaway-picker-offline/
```

For MAMP, place the contents of the `picker/` folder into a local folder named:

```text
reddit-giveaway-picker-offline
```

inside your MAMP document root.

Then open:

```text
http://localhost/reddit-giveaway-picker-offline/
```

You can also upload a previously saved giveaway JSON package manually.

## Recommended giveaway-post format

For new giveaways, put the authoritative game list in the original post between these markers:

```text
[GAMES]
Game One
Game Two
Diablo IV [requires Battle.net]
Game Four
[/GAMES]
```

Use one game per line.

Trailing bracketed notes are treated as notes rather than part of the matching title.

See `docs/GIVEAWAY_POST_FORMAT.md` for more detail.

## Winner-selection method

1. The picker builds the complete pool of eligible entrants.
2. The entire pool is randomized **once**.
3. Starting at randomized entrant #1, the picker checks that person's ranked game choices from first to last.
4. The entrant receives the highest-ranked requested game that is still available.
5. That game is removed from the available pool.
6. If all of the entrant's requested games have already been taken, the entrant is skipped.
7. The picker continues down the same fixed randomized order until all games are awarded or no eligible choices remain.

The full randomized entrant order is retained for audit purposes.

## Requirements

- A modern web browser
- Old Reddit for extraction
- PHP for the picker
- A local web server such as MAMP, XAMPP, or a similar PHP-capable environment

## Privacy / API use

The extractor works directly with the Reddit page already loaded in the browser. It does not use Reddit API credentials.

The picker runs locally on your computer. Giveaway data does not need to be sent to a third-party service.

## Versioning

The extractor and picker are versioned independently because they can receive changes at different times.

A GitHub Release can bundle both components together, for example:

```text
Reddit Giveaway Tools v1.0
- Extractor v3.5
- Picker v2.9.1
```

## License

This project is licensed under the MIT License.

Copyright (c) 2026 devoidzer0

See the `LICENSE` file for the full license text.
