# Reddit Giveaway Picker v2.9.1

## New in v2.9.1

The picker can receive a giveaway package directly from Reddit Giveaway Extractor v3.5.

From the extractor, click **Open in GIVEAWAY PICKER**. The extractor opens:

`http://localhost/reddit-giveaway-picker-offline/`

in a new tab and POSTs the giveaway-package JSON directly to the picker. The picker immediately imports and parses it, so there is no JSON file to select manually.

The normal JSON file upload remains available as a fallback.

## Installation

Place this folder in your MAMP document root as:

`reddit-giveaway-picker-offline`

so the picker is available at:

`http://localhost/reddit-giveaway-picker-offline/`
