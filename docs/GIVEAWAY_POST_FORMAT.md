# Recommended Giveaway Post Format

For new giveaways, place the authoritative game list between explicit markers:

```text
[GAMES]
Artisan TD
Assassin's Creed Valhalla
Diablo IV [requires Battle.net]
The Quarry Deluxe Edition
[/GAMES]
```

Guidelines:

- Put one game per line.
- Keep the actual game title first.
- Put optional notes at the end in square brackets.
- Do not put usernames or winner information in the list before the giveaway ends.

The extractor strips a trailing bracketed note from the title used for matching. For example:

```text
Diablo IV [requires Battle.net]
```

is treated as the game title:

```text
Diablo IV
```

Older completed giveaways can still be parsed when the list contains legacy winner/TBD annotations.
