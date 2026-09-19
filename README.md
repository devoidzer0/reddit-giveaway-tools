# Reddit Giveaway Tools

A two-part, local-first toolkit for running ranked-choice Steam game giveaways from Reddit.

Current components:

- **Reddit Giveaway Extractor v3.5.4**
- **Reddit Giveaway Picker v2.11**

The intended workflow is:

**Old Reddit thread → Extractor → Giveaway Picker → Review ambiguous choices → Add Steam keys → Randomize entrants → Assign games → Contact winners → Export results/audit**

## What the tools do

### 1. Reddit Giveaway Extractor

The extractor is a bookmarklet designed for **Old Reddit**. It:

- loads all available comments without repeatedly scrolling the page;
- identifies top-level comments used as giveaway entries;
- reads the giveaway game list from the original post;
- supports a preferred `[GAMES] ... [/GAMES]` block for new giveaways;
- supports game-list lines that include Steam or other HTTP/HTTPS URLs after the game title;
- preserves title characters such as `|` and `~`;
- keeps compatibility with older completed giveaways that contain winner/TBD annotations;
- exports a single JSON giveaway package containing the verified game list, top-level comments, and thread metadata;
- can open the local Giveaway Picker directly with that package already loaded;
- automatically downloads a JSON backup when **Open in GIVEAWAY PICKER + Save Backup** is used;
- uses a Safari-compatible bookmarklet wrapper.

No Reddit API or OAuth credentials are used.

### 2. Reddit Giveaway Picker

The picker is a local PHP app. It:

- imports the extractor's single JSON giveaway package;
- accepts direct handoff from the extractor at `http://localhost/reddit-giveaway-picker-offline/`;
- parses each entrant's ranked game choices;
- automatically handles common formatting variations, abbreviations, misspellings, and run-on lists;
- flags genuinely uncertain choices for manual review;
- allows a review fragment to be resolved into multiple ranked games when necessary;
- randomizes the **entire eligible entrant pool once**;
- walks through that fixed randomized order and awards each person their highest-ranked game still available;
- continues until every game has been awarded or no eligible choices remain;
- keeps the complete randomized entrant order for auditing;
- stores Steam keys locally for the active PHP session;
- can fill all Steam-key fields with fake test keys for testing;
- generates a private award message for every winner;
- opens a Reddit private-message compose page with the subject **Steam Giveaway WINNER** already supplied;
- provides a fallback link to the winner's Reddit profile for using the **Start Chat** control;
- tracks each winner as **Not sent**, **Award message sent**, or **Unable to contact**;
- returns to the exact winner/card after a contact-status update;
- separates winner-selection results from the private winner-contact workflow;
- exports both a concise public results HTML file and a full draw-audit HTML file.

Steam keys and winner-contact information are intentionally excluded from the exported public results and full draw-audit files.

Listing more acceptable games can improve an entrant's chance of winning *something*, because if an earlier choice has already been taken the picker can move to that entrant's next ranked choice.

## Repository layout

```text
reddit-giveaway-tools/
├── README.md
├── CHANGELOG.md
├── LICENSE
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
5. Verify the parsed game list.
6. Click **Auto-load ALL comments (Old Reddit)**.
7. Click **Open in GIVEAWAY PICKER + Save Backup**.

That single action:

1. downloads the giveaway-package JSON as a local backup;
2. opens the localhost Giveaway Picker;
3. transfers the same package to the picker automatically.

You can also use **Download GIVEAWAY PACKAGE (.json)** by itself and import the saved file manually later.

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

A previously saved giveaway JSON package can also be uploaded manually.

## Recommended giveaway-post format

For new giveaways, put the authoritative game list in the original post between these markers:

```text
[GAMES]
Atomic Heart: https://store.steampowered.com/app/668580/Atomic_Heart/
Keylocker | Turn Based Cyberpunk Action: https://store.steampowered.com/app/1325040/Keylocker__Turn_Based_Cyberpunk_Action/
Pocket Mirror ~ GoldenerTraum: https://store.steampowered.com/app/1899060/Pocket_Mirror__GoldenerTraum/
[/GAMES]
```

Use one game per line.

The extractor removes a trailing Steam/HTTP(S) URL from the matching title while preserving title characters such as `|` and `~`.

Trailing bracketed notes are also supported:

```text
Diablo IV [requires Battle.net]
```

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

## Steam keys and winner award messages

After the giveaway has been imported and reviewed, the picker includes a **Steam keys** section with one field for each game.

Keys are stored only in the current local PHP session. They are not added to the giveaway package, public results export, or draw-audit export.

For testing, **Fill All with Fake Test Keys** fills every Steam-key field with an obviously fake key.

After the drawing is complete, the picker opens a separate **Winner Contact Page**. Each winner receives an automatically generated award message in this format:

```text
Hi, you entered my Steam Giveaway Raffle on /r/steam_giveaway. Congrats, you are one of the 7 randomly selected winners!

You won the Steam key for [game title]! You will find your key below. Enjoy!

[game key]
```

The winner count, game title, and key are filled automatically.

Each winner card includes:

- **Copy Message + Open Private Message** — copies the complete award message and opens Reddit's private-message compose page;
- the private-message subject is automatically set to **Steam Giveaway WINNER**;
- **Open Profile / Start Chat** — opens the winner's profile so Reddit's Start Chat control can be used as an alternate contact method;
- **Mark Sent**;
- **Mark Unable to Contact**.

## Winner contact tracking

The Winner Contact Page also contains a summary/table showing every winner and one of three statuses:

- **Not sent**
- **Award message sent**
- **Unable to contact**

Status changes are manual because the local picker cannot reliably confirm whether Reddit actually accepted or delivered a message.

After a status is changed, the picker returns to the exact winner/card or status row that was updated instead of jumping back to the top of the page.

## Results and audit exports

After the draw, the picker first displays a dedicated **Selection Results** page containing:

- Assignments
- Skipped users encountered before allocation ended
- Unassigned games
- Draw audit
- Full randomized entrant order

Two HTML exports are available:

### Public Results HTML

A concise file intended for public sharing. It includes the awarded games, winner usernames, preference rank, draw timestamp, and any unassigned games.

### Full Draw Audit HTML

A private audit file containing:

- assignments;
- skipped users;
- unassigned games;
- draw audit counts;
- the complete randomized entrant order.

Steam keys and winner-contact information are deliberately excluded from both exports.

## Requirements

- A modern web browser
- Old Reddit for extraction
- PHP
- A local web server such as MAMP, XAMPP, or another PHP-capable environment

The extractor has been designed to work in both Chrome and Safari.

## Privacy / API use

The extractor works directly with the Reddit page already loaded in the browser. It does not use Reddit API credentials.

The picker runs locally on your computer. Giveaway data and Steam keys do not need to be sent to a third-party service by the picker.

The Reddit private-message/profile buttons open Reddit itself in the browser when you are ready to contact a winner.

## Versioning

The extractor and picker are versioned independently because they can receive changes at different times.

The current tested combination is:

```text
Reddit Giveaway Tools
- Extractor v3.5.4
- Picker v2.11
```

## License

This project is licensed under the MIT License.

Copyright (c) 2026 devoidzer0

See the `LICENSE` file for the full license text.
