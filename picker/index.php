<?php
declare(strict_types=1);
session_start();

const APP_NAME = 'Reddit Giveaway Picker — Ranked Random Order (Offline) v2.9.1';
const MAX_UPLOAD_BYTES = 10_000_000;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


function normalizeSpace(string $s): string {
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

function parseGames(string $text): array {
    $games = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $g = normalizeSpace(ltrim($line, "\xEF\xBB\xBF"));
        if ($g !== '') $games[mb_strtolower($g, 'UTF-8')] = $g;
    }
    return array_values($games);
}

function simplify(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
}

function tokens(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    return array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: []));
}

function titleSimilarity(string $candidate, string $game): float {
    // This is one of the hottest functions in the parser. Cache both the
    // normalized strings and the final pair score for the lifetime of the
    // request; the same fragments/windows are compared many times.
    static $simpleCache = [];
    static $scoreCache = [];
    $pairKey = $candidate . "\0" . $game;
    if (isset($scoreCache[$pairKey])) return $scoreCache[$pairKey];
    $a = $simpleCache[$candidate] ??= simplify($candidate);
    $b = $simpleCache[$game] ??= simplify($game);
    if ($a === '' || $b === '') return $scoreCache[$pairKey] = 0.0;
    if ($a === $b) return $scoreCache[$pairKey] = 1.0;
    $max = max(strlen($a), strlen($b));
    return $scoreCache[$pairKey] = ($max ? max(0.0, 1.0 - (levenshtein($a, $b) / $max)) : 1.0);
}

function fragmentLikelyGame(string $fragment, array $games): bool {
    static $cache = [];
    $gameKey = implode("\x1F", $games);
    $rawKey = $gameKey . "\0" . $fragment;
    if (array_key_exists($rawKey, $cache)) return $cache[$rawKey];
    $fragment = stripPreferenceNoise(trim($fragment));
    if ($fragment === '' || mb_strlen($fragment, 'UTF-8') < 2) return false;

    foreach ($games as $game) {
        $score = titleSimilarity($fragment, $game);
        $score = max($score, acronymMatchScore($fragment, $game));
        $fs = simplify($fragment);
        $gs = simplify($game);
        if ($fs !== '' && strlen($fs) >= 3 && (str_contains($gs, $fs) || str_contains($fs, $gs))) {
            $score = max($score, 0.76);
        }
        if ($score >= 0.48) return $cache[$rawKey] = true;
    }
    return $cache[$rawKey] = false;
}

function inlineGameSequence(string $line, array $games): array {
    static $cache = [];
    $cacheKey = implode("\x1F", $games) . "\0" . $line;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    // Find several game choices written back-to-back with no commas/newlines,
    // e.g. "V Rising Assassin's Creed Valhalla Crysis 3 remastered Diablo IV".
    // We scan word windows, keep only strong matches, then choose a
    // non-overlapping left-to-right sequence. Lower-confidence fragments are
    // left for the normal manual-review path rather than guessed here.
    $clean = stripPreferenceNoise($line);
    $wordText = preg_replace('/[^\p{L}\p{N}\'’]+/u', ' ', $clean) ?? $clean;
    $words = array_values(array_filter(preg_split('/\s+/u', trim($wordText)) ?: []));
    if (count($words) < 2) return $cache[$cacheKey] = [];

    $candidates = [];
    $maxWindow = min(9, count($words));
    for ($start = 0; $start < count($words); $start++) {
        for ($len = 1; $len <= $maxWindow && $start + $len <= count($words); $len++) {
            $fragment = implode(' ', array_slice($words, $start, $len));
            $best = null; $bestScore = 0.0; $second = 0.0;
            $aliasGame = safeCommonAliasMatch($fragment, $games);
            foreach ($games as $game) {
                $score = titleSimilarity($fragment, $game);
                if ($aliasGame === $game) $score = max($score, 0.98);
                $score = max($score, acronymMatchScore($fragment, $game));
                $fs = simplify($fragment); $gs = simplify($game);
                if ($fs !== '' && $gs !== '') {
                    if ($fs === $gs) $score = 1.0;
                    elseif (strlen($fs) >= 5 && str_contains($gs, $fs)) {
                        $coverage = strlen($fs) / max(1, strlen($gs));
                        $score = max($score, min(0.95, 0.80 + 0.15 * $coverage));
                    }
                }
                if ($score > $bestScore) { $second = $bestScore; $bestScore = $score; $best = $game; }
                elseif ($score > $second) $second = $score;
            }
            if ($best === null) continue;
            // Exact/near-exact windows and established acronym matches only.
            // Partial titles need substantial coverage to avoid false positives.
            $accept = $bestScore >= 0.92 && ($bestScore - $second) >= 0.035 && !numericConflict($fragment, (string)$best);
            if (!$accept) continue;
            $candidates[] = ['start'=>$start,'end'=>$start+$len-1,'len'=>$len,'game'=>$best,'score'=>$bestScore,'fragment'=>$fragment];
        }
    }
    if (!$candidates) return $cache[$cacheKey] = [];

    // Prefer longer and stronger candidates at each starting position.
    usort($candidates, function($a,$b){
        if ($a['start'] !== $b['start']) return $a['start'] <=> $b['start'];
        if ($a['len'] !== $b['len']) return $b['len'] <=> $a['len'];
        return $b['score'] <=> $a['score'];
    });

    $out = []; $cursor = 0; $seen = [];
    while ($cursor < count($words)) {
        $choices = array_values(array_filter($candidates, fn($c) => $c['start'] === $cursor));
        if ($choices) {
            $c = $choices[0];
            $k = mb_strtolower($c['game'], 'UTF-8');
            if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $c; }
            $cursor = $c['end'] + 1;
            continue;
        }
        $cursor++;
    }
    return $cache[$cacheKey] = (count($out) >= 2 ? $out : []);
}

function preferenceSegments(string $body, array $games = []): array {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = str_replace(["•", "·", "▪", "◦", "‣", "–", "—", "−"], ["\n", "\n", "\n", "\n", "\n", " - ", " - ", " - "], $body);

    // Split numbered choices even when several are on one physical line.
    // Inline list numbers must use punctuation (1., 2), 3:, etc.).
    // Bare numbers are NOT inline separators, so titles like Crysis 3
    // and Wizard of Legend 2 stay intact.
    $body = preg_replace('/(?<!^)(?<!\n)\s+(?=(?:#?\d{1,3}\s*[\.\)\:\-]\s+))/u', "\n", $body) ?? $body;

    $rawLines = preg_split('/\n+/u', $body) ?: [];
    $segments = [];

    foreach ($rawLines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;

        // Accept "1 Game", "1. Game", "1) Game", etc.
        $line = preg_replace('/^\s*(?:#?\d{1,3}\s*(?:[\.\)\:\-]\s*|\s+)|[-*]\s*)/u', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '') continue;

        // If punctuation alone differs from a master title (e.g. V - Rising),
        // keep it together rather than treating the dash as a separator.
        $wholeMatches = [];
        foreach ($games as $game) {
            if (simplify($line) === simplify($game)) $wholeMatches[] = $game;
        }
        if (count($wholeMatches) === 1) {
            $segments[] = $line;
            continue;
        }

        // v2.8: keep Tomb Raider Roman-numeral ranges together. A phrase such
        // as "Tomb Raider IV- VI remastered" is one title, not a dash-separated list.
        if (preg_match('/^\s*tomb\s+raider\s+iv\s*-\s*(?:v\s*-\s*)?vi\s+remaster(?:ed|es)?\s*[.!]?\s*$/iu', $line)) {
            $segments[] = $line;
            continue;
        }

        // Split explicit same-line list separators.
        $parts = preg_split('/\s*(?:;|\||,\s*|\s\/\s|\s+-\s*|\s*-\s+|\s+\&\s+|\s+\bor\b\s+|\s+\band\b\s+)\s*/iu', $line) ?: [$line];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        if (count($parts) > 1) {
            $plausible = 0;
            foreach ($parts as $part) if (fragmentLikelyGame($part, $games)) $plausible++;
            if ($plausible >= 2) {
                foreach ($parts as $part) $segments[] = $part;
                continue;
            }
        }

        // Locate exact master titles anywhere on the line.
        $hits = [];
        foreach ($games as $game) {
            $pos = mb_stripos($line, $game, 0, 'UTF-8');
            if ($pos !== false) $hits[] = ['pos'=>$pos, 'len'=>mb_strlen($game,'UTF-8'), 'game'=>$game];
        }
        usort($hits, fn($a,$b) => $a['pos'] <=> $b['pos']);

        if (count($hits) > 1) {
            $seen = [];
            foreach ($hits as $hit) {
                $k = mb_strtolower($hit['game'], 'UTF-8');
                if (!isset($seen[$k])) { $seen[$k]=true; $segments[]=$hit['game']; }
            }
            continue;
        }

        // If exactly one title is literal but text before/after it resembles a
        // second title, split the line around the exact title. This catches
        // "AC Valhalla Diablo IV" and similar back-to-back lists.
        if (count($hits) === 1) {
            $hit = $hits[0];
            $before = trim(mb_substr($line, 0, $hit['pos'], 'UTF-8'), " \t,;|&-");
            $afterStart = $hit['pos'] + $hit['len'];
            $after = trim(mb_substr($line, $afterStart, null, 'UTF-8'), " \t,;|&-");
            $beforeGame = fragmentLikelyGame($before, $games);
            $afterGame = fragmentLikelyGame($after, $games);
            if ($beforeGame || $afterGame) {
                if ($beforeGame) $segments[] = $before;
                $segments[] = $hit['game'];
                if ($afterGame) $segments[] = $after;
                continue;
            }
        }

        // Finally, handle several titles written back-to-back with no
        // separators. This is common in giveaway comments and is the main
        // reason a line-oriented parser misses ranked choices.
        $inline = inlineGameSequence($line, $games);
        if ($inline) {
            foreach ($inline as $hit) $segments[] = $hit['fragment'];
            continue;
        }

        $segments[] = $line;
    }

    return $segments;
}

function stripPreferenceNoise(string $segment): string {
    $s = trim($segment);

    // Remove conversational lead-ins without throwing away a game title that
    // appears later in the same sentence. Apply repeatedly because comments
    // often stack them: "Hi, I would like to be considered for ...".
    for ($i=0; $i<3; $i++) {
        $before = $s;
        $s = preg_replace('/^\s*(?:hi|hello|hey|hiya)\b\s*[,!:\-]*\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/^\s*(?:i(?:\'|’)d\s+like|i\s+would\s+like|i\s+would\s+love|i\s+want|i\s+just\s+want|just\s+want|just|can\s+i\s+just\s+have|i\s+would\s+be\s+fine\s+with\s+just|i\s+am\s+entering|i\'m\s+entering|entering|please\s+enter\s+me|please\s+consider\s+me|consider\s+me)\s+(?:to\s+be\s+considered\s+for|to\s+enter\s+for|for)?\s*[:\-]?\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/^\s*(?:to\s+be\s+considered\s+for|be\s+considered\s+for|considered\s+for|my\s+(?:pick|choice|choices)\s*(?:is|are)?|for\s+me\s+it(?:\'|’)s)\s*[:\-]?\s*/iu', '', $s) ?? $s;
        $s = preg_replace('/^\s*(?:honestly\??|hiya\s+papaya|tbh)\s*[:,!\-]?\s*/iu', '', $s) ?? $s;
        if ($s === $before) break;
    }

    // Harmless trailing conversation/courtesy text after the actual choice.
    $s = preg_replace('/(?:[\s\.,!;:\-]+)(?:thanks?|thank\s+you|thanx|thx|cheers|good\s+luck|gl\b|appreciate\s+it|please|pls|doumo|if\s+possible|if\s+that(?:\'|’)s\s+okay|would\s+be\s+great)\b.*$/iu', '', $s) ?? $s;
    return trim($s, " \t\n\r\0\x0B,.;:!-");
}

function normalizedTitleWords(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(["’", "`"], "'", $s);
    $s = preg_replace("/'s\\b/u", '', $s) ?? $s;
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    $parts = array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: []));
    $roman = ['i'=>'1','ii'=>'2','iii'=>'3','iv'=>'4','v'=>'5','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10'];
    foreach ($parts as &$p) if (isset($roman[$p])) $p = $roman[$p];
    unset($p);
    return $parts;
}

function significantTitleWords(string $s): array {
    $stop = ['the'=>true,'a'=>true,'an'=>true,'of'=>true,'for'=>true,'to'=>true,'and'=>true,'or'=>true,'is'=>true,'are'=>true,'in'=>true,'on'=>true,'my'=>true,'i'=>true,'me'=>true,'please'=>true,'pls'=>true,'thanks'=>true,'thank'=>true,'you'=>true,'thx'=>true,'good'=>true,'luck'=>true,'giveaway'=>true,'chance'=>true,'op'=>true,'everyone'=>true];
    return array_values(array_filter(normalizedTitleWords($s), fn($w) => !isset($stop[$w]) && mb_strlen($w, 'UTF-8') >= 2));
}

function acronymMatchScore(string $segment, string $game): float {
    $sw = significantTitleWords($segment); $gw = significantTitleWords($game);
    if (!$sw || count($gw) < 2) return 0.0;
    for ($n=2; $n<=min(4,count($gw)); $n++) {
        $acronym=''; for($i=0;$i<$n;$i++) $acronym .= mb_substr($gw[$i],0,1,'UTF-8');
        if (($sw[0]??'') !== $acronym) continue;
        $remainingGame=array_slice($gw,$n); $remainingSeg=array_slice($sw,1);
        if (!$remainingGame && !$remainingSeg) return 0.98;
        $hits=0; foreach($remainingGame as $word) if(in_array($word,$remainingSeg,true)) $hits++;
        if ($remainingGame) { $coverage=$hits/count($remainingGame); if($coverage>=1.0) return 0.97; if($coverage>=0.5) return 0.91; }
    }
    return 0.0;
}

function hasSuspiciousSubtitle(string $segment, string $game): bool {
    $segWords=normalizedTitleWords(stripPreferenceNoise($segment)); $gameWords=normalizedTitleWords($game);
    if(!$segWords||!$gameWords||count($segWords)<=count($gameWords)) return false;
    for($i=0;$i<count($gameWords);$i++) if(($segWords[$i]??null)!==$gameWords[$i]) return false;
    $extra=array_slice($segWords,count($gameWords)); $allowed=['remastered','remaster','deluxe','edition','complete','goty'];
    foreach($extra as $word) if(!in_array($word,$allowed,true)) return true;
    return false;
}

function titleVariants(string $game): array {
    $variants = [$game];
    // Entrants commonly omit subtitles and edition/remaster suffixes.
    if (str_contains($game, ':')) {
        $base = trim(explode(':', $game, 2)[0]);
        if (mb_strlen($base, 'UTF-8') >= 5) $variants[] = $base;
    }
    $trimmed = preg_replace('/\s+(?:deluxe(?:\s+edition)?|remastered|remaster|complete(?:\s+edition)?|goty(?:\s+edition)?)$/iu', '', $game) ?? $game;
    if ($trimmed !== $game && mb_strlen(trim($trimmed), 'UTF-8') >= 5) $variants[] = trim($trimmed);
    return array_values(array_unique($variants));
}

function uniqueVariantMatch(string $segment, array $games): ?string {
    $seg = implode(' ', normalizedTitleWords(stripPreferenceNoise($segment)));
    if ($seg === '') return null;
    $hits = [];
    foreach ($games as $game) {
        foreach (titleVariants($game) as $variant) {
            $v = implode(' ', normalizedTitleWords($variant));
            if ($v !== '' && $seg === $v) { $hits[$game] = true; break; }
        }
    }
    return count($hits) === 1 ? array_key_first($hits) : null;
}

function obviousNonChoice(string $segment): bool {
    $s = mb_strtolower(trim($segment), 'UTF-8');
    if ($s === '') return true;
    $plain = trim(preg_replace('/[^\p{L}\p{N}<3]+/u', ' ', $s) ?? $s);
    if ($plain === '' || $plain === 'the' || $plain === 'or' || $plain === 'v') return true;

    // Standalone courtesy / connective / list-introduction fragments. These
    // are applied only AFTER dictionary/title matching, so a sentence that
    // actually contains a listed game still wins before reaching this filter.
    if (preg_match('/^(?:thanks?|thank\s*you|thankyou|ty|tysm|tyvm|thx|thanx|thnx|thks|cheers|kudos|many\s+thanks|big\s+thanks|t\s+thanks)\b/iu', $s)) return true;
    if (preg_match('/^(?:good\s*luck|goodluck|gl\b|appreciate|have\s+a\s+(?:great|nice)|awesome|great\s+giveaway|hello|hi|hey|hiya|please\.?$|doumo)\b/iu', $s)) return true;
    if (preg_match('/^(?:and\s+)?thanks?[!. :)*_-]*$/iu', $s)) return true;
    if (preg_match('/^(?:ty|tysm|tyvm|thx|thanx|thnx|thks)[!. <3:)*_-]*$/iu', $s)) return true;
    if (preg_match('/^(?:not\s+entering\b|any(?:one|thing)\s+(?:would\s+)?(?:work|be\s+(?:fine|great|good))\b)/iu', $s)) return true;
    if (preg_match('/^(?:here\s+for|i\s+would\s+like|i(?:\'|’)m\s+only\s+interested\s+in|if\s+available|i(?:\'|’)ll\s+bite|i(?:\'|’)d\s+like|these\s+are\s+the\s+ones\s+i(?:\'|’)m\s+interested\s+in)\s*[:.!-]*\s*$/iu', $s)) return true;
    if (preg_match('/^(?:here(?:\'|’)s\s+my\s+list.*|top\s*5.*|i(?:\'|’)m\s+most\s+interested.*|entering\s+for\s+only.*|that(?:\'|’)s\s+it.*)$/iu', $s)) return true;
    if (preg_match('/^\s*<3\s*$/u', $s)) return true;
    if (preg_match('/^i\s+really\s+hope\s+i\s+get\s+.+?(?:thanks?|thank\s+you|thx)\b.*$/iu', $s)) return true;
    if (preg_match('/^(?:oh\s+my\s+god|i(?:\'|’)m\s+the\s+winner.*|anyone\s+will\s+be\s+gold\s+for\s+me|gotta\s+go\s+fast.*|yeah\s+i(?:\'|’)m\s+kind\s+of\s+easy.*|very\s+nice\s+list|lots\s+of\s+great\s+titles.*|gonna\s+try\s+the\s+most\s+impossible\s+luck|i(?:\'|’)d\s+like\s*-?)$/iu', $s)) return true;
    if (preg_match('/^(?:these\s+are\s+the\s+games\s+that\s+i\s+would\s+prefer.*|i\s+think\s+it\s+would\s+be\s+better\s+to\s+just\s+choose\s+randomly.*)$/iu', $s)) return true;
    if (preg_match('/^(?:that(?:\'|’)s\s+it\s+for\s+me.*|lots\s+of\s+great\s+titles.*)$/iu', $s)) return true;
    return false;
}

function numericConflict(string $segment, string $game): bool {
    $roman = ['i'=>'1','ii'=>'2','iii'=>'3','iv'=>'4','v'=>'5','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10'];
    $nums = function(string $x) use ($roman): array {
        $words = normalizedTitleWords($x);
        $out=[]; foreach($words as $w) if (ctype_digit($w)) $out[$w]=true;
        return array_keys($out);
    };
    $a=$nums($segment); $b=$nums($game);
    if (!$a || !$b) return false;
    return count(array_intersect($a,$b))===0;
}

function safeCommonAliasMatch(string $segment, array $games): ?string {
    $seg = implode(' ', normalizedTitleWords(stripPreferenceNoise($segment)));
    if ($seg === '') return null;

    $aliases = [];
    foreach ($games as $game) {
        $g = implode(' ', normalizedTitleWords($game));
        // Common franchise abbreviations that remain unambiguous only when the
        // corresponding title is actually present in this giveaway's game list.
        if (str_starts_with($g, 'shin megami tensei 5')) {
            foreach (['smt','smt 5','smt5','smt v','smtv','megaten v','mega ten v','smt 5 vengeance','smt 5 vengance','shin megami tensei 5','shin megami tensei v','shin megami tense v','shin megami tense v vengeance','shin megami tense v vengence','shin magami tensi v vengeance','shin magami tensi v vengance'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'assassin creed valhalla') || str_starts_with($g, 'assassins creed valhalla')) {
            foreach (['ac valhalla','ac valhall','assassins creed valhalla','assassin creed valhalla','assasins creed valhalla','assain creed valhalla','assassins creed','assassin creed','assasins creed','assain creed'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'the quarry')) { foreach (['quarry','the quarry','the quary'] as $a) $aliases[$a][$game]=true; }
        if (str_starts_with($g, 'daemon x machina')) {
            foreach (['daemon x machina','demon x machina','daemin x machina'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'crysis 3')) {
            foreach (['crysis 3','crysis3','crysis 3 remaster','crisis 3','crisis3','crisis 3 remaster'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'life is strange double exposure')) {
            foreach (['life is strange','life is strange de','lis de','lis d e'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'tomb raider')) {
            foreach (['tomb raider','tom raider','tomraider','tomb raider remasteres','tomb raider bundle','tomb raider 4 5 6','tomb raider 4 5 6 remastered','tomb raider 4 6 remastered'] as $a) $aliases[$a][$game]=true;
        }
        if ($g === 'wildmender') {
            foreach (['wildmeter','wild mender'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'pharaoh a new era')) {
            foreach (['pharaoh','pharao','pharoah','pharaoh a new era','pharao a new era'] as $a) $aliases[$a][$game]=true;
        }
        if ($g === 'lil gator game') {
            foreach (['lil gator game','little gator game'] as $a) $aliases[$a][$game]=true;
        }
        if (str_starts_with($g, 'intravenous 2')) {
            foreach (['intravenous','intervenous','intravenous 2','intervenous 2'] as $a) $aliases[$a][$game]=true;
        }
    }
    if (!isset($aliases[$seg]) || count($aliases[$seg]) !== 1) return null;
    return array_key_first($aliases[$seg]);
}


function v28KnownCleanupMatch(string $segment, array $games): ?string {
    $s = implode(' ', normalizedTitleWords(stripPreferenceNoise($segment)));
    if ($s === '') return null;
    $want = null;
    if (preg_match('/^crisis 3(?: remaster(?:ed)?)?$/u', $s)) $want = 'crysis 3';
    elseif (preg_match('/^tomb raider (?:remasteres|bundle|4 5 6|4 5 6 remastered|4 6 remastered)$/u', $s)) $want = 'tomb raider';
    elseif (preg_match('/^(?:daemin|demon) x machina$/u', $s)) $want = 'daemon x machina';
    elseif (preg_match('/^pharao$/u', $s)) $want = 'pharaoh';
    elseif (preg_match('/^(?:shin megami tense v|shin megami tensei v|smt 5 vengance|smt 5 vengeance)$/u', $s)) $want = 'shin megami tensei 5';
    if ($want === null) return null;
    $hits=[];
    foreach ($games as $game) {
        $g = implode(' ', normalizedTitleWords($game));
        if (str_starts_with($g, $want)) $hits[$game]=true;
    }
    return count($hits) === 1 ? array_key_first($hits) : null;
}



// v2.8.1: tightly scoped resolutions verified against the remaining v2.8
// diagnostics. These do not lower the general fuzzy thresholds.
function gameByNormalizedPrefix(string $prefix, array $games): ?string {
    $hits = [];
    foreach ($games as $game) {
        $g = implode(' ', normalizedTitleWords($game));
        if (str_starts_with($g, $prefix)) $hits[$game] = true;
    }
    return count($hits) === 1 ? array_key_first($hits) : null;
}

function v281VerifiedCleanupMatch(string $segment, array $games): ?string {
    $clean = stripPreferenceNoise($segment);
    $words = implode(' ', normalizedTitleWords($clean));
    $compact = simplify($clean);

    // Diagnostics-verified typo/abbreviation forms only.
    if (preg_match('/^crisis 3(?: remaster(?:ed)?)?$/u', $words) ||
        preg_match('/^crisis3(?:remaster(?:ed)?)?$/u', $compact)) {
        return gameByNormalizedPrefix('crysis 3', $games);
    }
    if (in_array($words, ['shin megami tense 5','shin megami tensei 5'], true)) {
        return gameByNormalizedPrefix('shin megami tensei 5', $games);
    }
    if (in_array($compact, ['daeminxmachina','demonxmachina'], true)) {
        return gameByNormalizedPrefix('daemon 10 machina', $games);
    }
    if (in_array($words, ['pharao','pharoah'], true) || in_array($compact, ['pharaoplease','pharoahplease'], true)) {
        return gameByNormalizedPrefix('pharaoh', $games);
    }
    return null;
}

function v281VerifiedRunOnSequence(string $segment, array $games): array {
    $s = mb_strtolower($segment, 'UTF-8');
    $s = str_replace(["’", "`"], "'", $s);
    $s = preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $s) ?? $s;
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));

    // One remaining regression case contains two heavily misspelled titles
    // back-to-back. Both phrases are distinctive and their order is explicit.
    if (preg_match('/\bassain\s+creed\s+valhalla\s+shin\s+magami\s+tensi\s+v\s+vengance\b/u', $s)) {
        $a = gameByNormalizedPrefix('assassin creed valhalla', $games);
        $b = gameByNormalizedPrefix('shin megami tensei 5', $games);
        if ($a !== null && $b !== null) return [$a, $b];
    }
    return [];
}

function uniqueDistinctiveWordMatch(string $segment, array $games): ?string {
    $words = significantTitleWords(stripPreferenceNoise($segment));
    if (count($words) !== 1 || mb_strlen($words[0],'UTF-8') < 6) return null;
    $needle=$words[0]; $hits=[];
    foreach($games as $game) {
        if (in_array($needle, significantTitleWords($game), true)) $hits[$game]=true;
    }
    return count($hits)===1 ? array_key_first($hits) : null;
}

function bestGameMatchForSegment(string $segment, array $games): array {
    static $cache = [];
    $cacheKey = implode("\x1F", $games) . "\0" . $segment;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    $originalCacheKey = $cacheKey;
    $segment = stripPreferenceNoise(trim($segment));
    // Remove a trailing parenthetical note (e.g. platform/account requirement)
    // and harmless emoticon/courtesy residue before matching.
    $segment = preg_replace('/\s*\([^)]{1,80}\)\s*$/u', '', $segment) ?? $segment;
    $segment = preg_replace('/\s+(?:good\s+sir|ty|thanks?|thank\s+you|thx|pls|please)\s*[!.,:;)*_-]*$/iu', '', $segment) ?? $segment;
    // Strip only actual emoticon tails. Do not treat a bare trailing 3 as an emoticon;
    // game titles such as Crysis 3 depend on that number.
    $segment = preg_replace('/\s*(?:[:;]-?[)o3]+)\s*$/iu', '', $segment) ?? $segment;
    $segment = trim($segment);
    if ($segment === '') return ['status'=>'review','game'=>null,'score'=>0.0,'reason'=>'could not identify a game'];

    // Recover a listed title embedded in a natural sentence. Comparing the
    // punctuation-free forms also handles curly vs straight apostrophes.
    $segSimpleEmbedded = simplify($segment);
    $embeddedHits = [];
    if ($segSimpleEmbedded !== '') {
        foreach ($games as $embeddedGame) {
            $gameSimpleEmbedded = simplify($embeddedGame);
            if ($gameSimpleEmbedded !== '' && strlen($gameSimpleEmbedded) >= 6 && str_contains($segSimpleEmbedded, $gameSimpleEmbedded) && !numericConflict($segment, $embeddedGame)) {
                $embeddedHits[$embeddedGame] = true;
            }
        }
    }
    if (count($embeddedHits) === 1) {
        $embeddedGame = array_key_first($embeddedHits);
        return ['status'=>'confident','game'=>$embeddedGame,'score'=>0.99,'reason'=>'listed title embedded in natural sentence'];
    }

    $variant = uniqueVariantMatch($segment, $games);
    if ($variant !== null && !numericConflict($segment,$variant)) return ['status'=>'confident','game'=>$variant,'score'=>0.99,'reason'=>'unique shortened title'];
    $cleanup281 = v281VerifiedCleanupMatch($segment, $games);
    if ($cleanup281 !== null && !numericConflict($segment,$cleanup281)) return ['status'=>'confident','game'=>$cleanup281,'score'=>0.99,'reason'=>'v2.8.1 diagnostics-verified variant'];
    $cleanup = v28KnownCleanupMatch($segment, $games);
    if ($cleanup !== null && !numericConflict($segment,$cleanup)) return ['status'=>'confident','game'=>$cleanup,'score'=>0.98,'reason'=>'v2.8 verified cleanup variant'];
    $alias = safeCommonAliasMatch($segment, $games);
    if ($alias !== null) return ['status'=>'confident','game'=>$alias,'score'=>0.98,'reason'=>'safe common abbreviation/variant'];
    $distinctive = uniqueDistinctiveWordMatch($segment, $games);
    if ($distinctive !== null) return ['status'=>'confident','game'=>$distinctive,'score'=>0.97,'reason'=>'unique distinctive title word'];
    $segNorm=implode(' ',normalizedTitleWords($segment)); $scores=[]; $subtitleConflict=[];
    foreach($games as $game){
        $gameNorm=implode(' ',normalizedTitleWords($game));
        if($segNorm!=='' && $segNorm===$gameNorm) return ['status'=>'confident','game'=>$game,'score'=>1.0,'reason'=>'exact title'];
        if(hasSuspiciousSubtitle($segment,$game)) $subtitleConflict[$game]=true;
        $best=acronymMatchScore($segment,$game);
        $segmentSimple=simplify($segment); $gameSimple=simplify($game);
        if($segmentSimple!==''&&$gameSimple!==''){
            if(str_contains($segmentSimple,$gameSimple)){ $coverage=strlen($gameSimple)/max(1,strlen($segmentSimple)); $best=max($best,min(0.91,0.72+0.19*$coverage)); }
            elseif(strlen($segmentSimple)>=4&&str_contains($gameSimple,$segmentSimple)){ $coverage=strlen($segmentSimple)/max(1,strlen($gameSimple)); $best=max($best,min(0.94,0.75+0.19*$coverage)); }
        }
        $segmentTokens=normalizedTitleWords($segment); $gameTokens=normalizedTitleWords($game); $n=count($gameTokens);
        foreach([$n-2,$n-1,$n,$n+1,$n+2] as $w){ if($w<1||$w>count($segmentTokens)) continue; for($i=0;$i<=count($segmentTokens)-$w;$i++){ $window=implode(' ',array_slice($segmentTokens,$i,$w)); $best=max($best,titleSimilarity($window,implode(' ',$gameTokens))); } }
        $best=max($best,titleSimilarity($segNorm,$gameNorm));
        $a=array_unique(significantTitleWords($segment)); $b=array_unique(significantTitleWords($game));
        if($a&&$b){ $overlap=count(array_intersect($a,$b)); if($overlap>0){ $tokenScore=$overlap/max(1,min(count($a),count($b))); $best=max($best,0.52+0.38*$tokenScore); } }
        $scores[$game]=min(1.0,$best);
    }
    arsort($scores); $ordered=array_keys($scores); $bestGame=$ordered[0]??null; $bestScore=$bestGame!==null?(float)$scores[$bestGame]:0.0; $secondScore=isset($ordered[1])?(float)$scores[$ordered[1]]:0.0;
    if($bestGame!==null && isset($subtitleConflict[$bestGame])) return ['status'=>'review','game'=>null,'score'=>$bestScore,'reason'=>'title has additional words; verify that it is the listed game'];
    if($bestGame!==null && !numericConflict($segment,$bestGame) && $bestScore>=0.92 && ($bestScore-$secondScore)>=0.05) return ['status'=>'confident','game'=>$bestGame,'score'=>$bestScore,'reason'=>'strong fuzzy/partial match'];
    // Conservative typo acceptance: short choice-like fragments only, a clear
    // lead over the runner-up, and never when title numbers conflict (Diablo I/IV).
    if($bestGame!==null && !numericConflict($segment,$bestGame) && $bestScore>=0.84 && ($bestScore-$secondScore)>=0.10 && count(significantTitleWords($segment))<=6)
        return ['status'=>'confident','game'=>$bestGame,'score'=>$bestScore,'reason'=>'unambiguous typo/abbreviation'];
    if($bestGame!==null && $bestScore>=0.75) return ['status'=>'review','game'=>$bestGame,'score'=>$bestScore,'reason'=>'possible abbreviation or misspelling'];
    return ['status'=>'review','game'=>null,'score'=>$bestScore,'reason'=>'could not identify a game'];
}

function parseRankedPreferences(string $body, array $games): array {
    // v2.6.1 dictionary-first parser: try to identify listed games BEFORE
    // deciding that text is prose. This prevents natural sentences such as
    // "I would like Assassin’s Creed Valhalla please" from being discarded.
    $segments = preferenceSegments($body, $games);
    $picks = [];
    $seenGames = [];

    foreach ($segments as $segment) {
        if (count($picks) >= 5) break;
        $segment = trim($segment);
        if ($segment === '') continue;

        // v2.8.1: resolve the one diagnostics-verified two-title run-on typo
        // before the general sequence scanner.
        $verifiedRunOn = v281VerifiedRunOnSequence($segment, $games);
        if ($verifiedRunOn) {
            foreach ($verifiedRunOn as $game) {
                if (count($picks) >= 5) break;
                $key = mb_strtolower($game, 'UTF-8');
                if (isset($seenGames[$key])) continue;
                $seenGames[$key] = true;
                $picks[] = [
                    'position'=>count($picks)+1,
                    'segment'=>$segment,
                    'status'=>'confident',
                    'game'=>$game,
                    'score'=>0.99,
                    'reason'=>'v2.8.1 verified run-on sequence',
                ];
            }
            continue;
        }

        // First look for a sequence of two or more game titles/aliases in the
        // segment. This is deliberately before prose filtering because run-on
        // lists often contain courtesy words at the end.
        $inline = inlineGameSequence($segment, $games);
        if ($inline) {
            foreach ($inline as $hit) {
                if (count($picks) >= 5) break;
                $game = (string)$hit['game'];
                $key = mb_strtolower($game, 'UTF-8');
                if (isset($seenGames[$key])) continue;
                $seenGames[$key] = true;
                $picks[] = [
                    'position'=>count($picks)+1,
                    'segment'=>(string)$hit['fragment'],
                    'status'=>'confident',
                    'game'=>$game,
                    'score'=>(float)$hit['score'],
                    'reason'=>'dictionary-first title sequence',
                ];
            }
            continue;
        }

        // A standalone courtesy word is never a game choice. This explicit
        // guard covers the final v2.8 diagnostics residue without changing
        // natural-sentence handling.
        if (preg_match('/^\s*please[.!?]*\s*$/iu', $segment)) continue;

        // Always attempt game matching before applying obviousNonChoice().
        // A confident dictionary/title match wins over conversational prose.
        $match = bestGameMatchForSegment($segment, $games);
        if ($match['status'] === 'confident' && $match['game']) {
            $key = mb_strtolower((string)$match['game'], 'UTF-8');
            if (!isset($seenGames[$key])) {
                $seenGames[$key] = true;
                $picks[] = [
                    'position'=>count($picks)+1,
                    'segment'=>$segment,
                    'status'=>'confident',
                    'game'=>$match['game'],
                    'score'=>$match['score'],
                    'reason'=>$match['reason'],
                ];
            }
            continue;
        }

        // Only residual text that did NOT produce a confident game match is
        // eligible for prose/courtesy filtering.
        if (obviousNonChoice($segment)) continue;

        // Long low-similarity residual text is ordinary prose. Short or
        // reasonably game-like fragments stay visible for human review.
        if ($match['status'] === 'review' && $match['game'] === null && $match['score'] < 0.45) {
            $wc = count(significantTitleWords($segment));
            if ($wc >= 5) continue;
        }

        if ($match['game']) {
            $key = mb_strtolower((string)$match['game'], 'UTF-8');
            if (isset($seenGames[$key])) continue;
            $seenGames[$key] = true;
        }

        $picks[] = [
            'position'=>count($picks)+1,
            'segment'=>$segment,
            'status'=>$match['status'],
            'game'=>$match['game'],
            'score'=>$match['score'],
            'reason'=>$match['reason'],
        ];
    }

    return $picks;
}

function dedupePreferenceGames(array $games): array {
    $seen = [];
    $out = [];
    foreach ($games as $game) {
        if (!$game) continue;
        $k = mb_strtolower((string)$game, 'UTF-8');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $game;
    }
    return $out;
}

function secureShuffle(array $items): array {
    $items = array_values($items);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

function allocateByRandomOrder(array $entrants, array $games): array {
    $randomOrder = secureShuffle($entrants);
    $available = array_fill_keys($games, true);
    $assignments = [];
    $skipped = [];

    foreach ($randomOrder as $drawPosition => $entrant) {
        $assigned = null;
        $assignedRank = null;

        foreach ($entrant['preferences'] as $rank => $game) {
            if (isset($available[$game]) && $available[$game]) {
                $assigned = $game;
                $assignedRank = $rank + 1;
                $available[$game] = false;
                break;
            }
        }

        if ($assigned !== null) {
            $assignments[] = [
                'draw_position' => $drawPosition + 1,
                'author' => $entrant['author'],
                'game' => $assigned,
                'preference_rank' => $assignedRank,
                'preferences' => $entrant['preferences'],
            ];
        } else {
            $skipped[] = [
                'draw_position' => $drawPosition + 1,
                'author' => $entrant['author'],
                'preferences' => $entrant['preferences'],
                'reason' => empty($entrant['preferences'])
                    ? 'No valid game preferences'
                    : 'All requested games had already been assigned',
            ];
        }

        if (!in_array(true, $available, true)) break;
    }

    $unassignedGames = [];
    foreach ($available as $game => $isAvailable) {
        if ($isAvailable) $unassignedGames[] = $game;
    }

    return [
        'random_order' => $randomOrder,
        'assignments' => $assignments,
        'skipped' => $skipped,
        'unassigned_games' => $unassignedGames,
    ];
}


function normalizeAuthor(string $author): string {
    $author = trim($author);
    $author = preg_replace('~^/?u/~i', '', $author) ?? $author;
    return trim($author);
}

function parseDelimitedText(string $text): array {
    $text = preg_replace("/^\xEF\xBB\xBF/", '', $text) ?? $text;
    if (trim($text) === '') return [];

    // Detect delimiter from the first physical line, but parse the ENTIRE
    // document as a CSV stream. This is essential because Reddit comments
    // frequently contain embedded newlines inside quoted CSV fields.
    $firstPhysicalLine = strtok($text, "\r\n") ?: '';
    $delimiter = substr_count($firstPhysicalLine, "\t") > substr_count($firstPhysicalLine, ',')
        ? "\t"
        : ",";

    $fh = fopen('php://temp', 'r+');
    if ($fh === false) return [];
    fwrite($fh, $text);
    rewind($fh);

    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if ($row === [null] || $row === []) continue;
        $rows[] = $row;
    }
    fclose($fh);

    if (!$rows) return [];

    $header = array_map(
        fn($v) => mb_strtolower(trim((string)$v), 'UTF-8'),
        $rows[0]
    );

    $authorNames = ['author','username','user','reddit username','reddit_user'];
    $bodyNames = ['body','comment','comment text','text','entry'];
    $authorIdx = null;
    $bodyIdx = null;

    foreach ($header as $i => $name) {
        if (in_array($name, $authorNames, true)) $authorIdx = $i;
        if (in_array($name, $bodyNames, true)) $bodyIdx = $i;
    }

    if ($authorIdx !== null && $bodyIdx !== null) {
        array_shift($rows);
    } else {
        $authorIdx = 0;
        $bodyIdx = 1;
    }

    $comments = [];
    foreach ($rows as $row) {
        if (!array_key_exists($authorIdx, $row) || !array_key_exists($bodyIdx, $row)) continue;

        $author = normalizeAuthor((string)$row[$authorIdx]);
        $body = trim((string)$row[$bodyIdx]);

        if ($author === '' || $body === '' || $author === '[deleted]') continue;
        $comments[] = ['author'=>$author, 'body'=>$body];
    }

    return $comments;
}

function readGamesImport(): string {
    $pasted = trim((string)($_POST['games'] ?? ''));
    if ($pasted !== '') return $pasted;
    if (!empty($_FILES['games_file']['tmp_name'])) {
        if ((int)($_FILES['games_file']['size'] ?? 0) > 1_000_000) {
            throw new RuntimeException('Game-list file is too large.');
        }
        $content = file_get_contents($_FILES['games_file']['tmp_name']);
        if ($content === false) throw new RuntimeException('Could not read game-list file.');
        return $content;
    }
    throw new RuntimeException('Paste the game list or upload the TXT exported by the Reddit extractor.');
}

function readImport(): string {
    $pasted = trim((string)($_POST['comments_text'] ?? ''));
    if ($pasted !== '') return $pasted;

    if (!empty($_FILES['comments_file']['tmp_name'])) {
        if ((int)($_FILES['comments_file']['size'] ?? 0) > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Uploaded file is too large. Maximum size is 10 MB.');
        }
        $content = file_get_contents($_FILES['comments_file']['tmp_name']);
        if ($content === false) throw new RuntimeException('Could not read uploaded file.');
        return $content;
    }
    throw new RuntimeException('Paste comment data or upload a CSV/TSV file.');
}


function readGiveawayPackage(): ?array {
    $posted = (string)($_POST['package_json'] ?? '');

    if ($posted !== '') {
        if (strlen($posted) > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Giveaway package is too large. Maximum size is 10 MB.');
        }
        $raw = $posted;
    } elseif (!empty($_FILES['package_file']['tmp_name'])) {
        if ((int)($_FILES['package_file']['size'] ?? 0) > MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Giveaway package is too large. Maximum size is 10 MB.');
        }
        $raw = file_get_contents($_FILES['package_file']['tmp_name']);
        if ($raw === false) throw new RuntimeException('Could not read the giveaway package.');
    } else {
        return null;
    }
    $raw = ltrim($raw, "\xEF\xBB\xBF");
    $pkg = json_decode($raw, true);
    if (!is_array($pkg)) throw new RuntimeException('The giveaway package is not valid JSON.');
    if (($pkg['schema'] ?? '') !== 'reddit-giveaway-package' || (int)($pkg['schema_version'] ?? 0) !== 1) {
        throw new RuntimeException('This is not a supported Reddit Giveaway Package (schema version 1).');
    }

    $gameValues = $pkg['games'] ?? null;
    $commentValues = $pkg['comments'] ?? null;
    if (!is_array($gameValues) || !is_array($commentValues)) {
        throw new RuntimeException('The giveaway package is missing its games or comments list.');
    }

    $games = parseGames(implode("\n", array_map(static fn($v)=>(string)$v, $gameValues)));
    if (!$games) throw new RuntimeException('The giveaway package contains no game titles.');

    $comments = [];
    foreach ($commentValues as $row) {
        if (!is_array($row)) continue;
        $author = normalizeAuthor((string)($row['author'] ?? ''));
        $body = trim((string)($row['comment'] ?? $row['body'] ?? ''));
        if ($author === '' || $author === '[deleted]' || $body === '') continue;
        $comments[] = ['author'=>$author, 'body'=>$body];
    }
    if (!$comments) throw new RuntimeException('The giveaway package contains no usable top-level comments.');

    $thread = is_array($pkg['thread'] ?? null) ? $pkg['thread'] : [];
    $op = normalizeAuthor((string)($thread['op_author'] ?? ''));
    $meta = [
        'thread_id'=>(string)($thread['id'] ?? ''),
        'title'=>(string)($thread['title'] ?? ''),
        'subreddit'=>(string)($thread['subreddit'] ?? ''),
        'source_url'=>(string)($thread['source_url'] ?? ''),
        'extractor_version'=>(string)($pkg['extractor_version'] ?? ''),
        'created_at'=>(string)($pkg['created_at'] ?? ''),
    ];

    return ['games'=>$games, 'comments'=>$comments, 'op_username'=>$op, 'meta'=>$meta];
}

function analyzeImportedComments(array $comments, array $games, string $opUsername): array {
    $opKey = mb_strtolower(normalizeAuthor($opUsername), 'UTF-8');
    $byUser = [];
    $ignoredOp = 0;

    foreach ($comments as $c) {
        $author = normalizeAuthor($c['author']);
        $key = mb_strtolower($author, 'UTF-8');
        if ($opKey !== '' && $key === $opKey) {
            $ignoredOp++;
            continue;
        }

        // Only the first imported top-level comment per user is used.
        if (!isset($byUser[$key])) {
            $byUser[$key] = [
                'author'=>$author,
                'body'=>$c['body'],
                'picks'=>parseRankedPreferences($c['body'], $games),
            ];
        }
    }

    return ['users'=>array_values($byUser),'ignored_op'=>$ignoredOp];
}


function parserDiagnosticRows(array $data): array {
    $rows = [];
    foreach (($data['analysis']['users'] ?? []) as $u) {
        $author = (string)($u['author'] ?? '');
        $body = (string)($u['body'] ?? '');
        $picks = $u['picks'] ?? [];

        if (!$picks) {
            $rows[] = [
                'author'=>$author, 'original_comment'=>$body, 'pick_position'=>'',
                'original_text'=>'', 'proposed_game'=>'', 'confidence_percent'=>'',
                'reason'=>'no preference lines detected', 'status'=>'no_choices'
            ];
        }

        foreach ($picks as $pick) {
            $rows[] = [
                'author'=>$author,
                'original_comment'=>$body,
                'pick_position'=>(string)($pick['position'] ?? ''),
                'original_text'=>(string)($pick['segment'] ?? ''),
                'proposed_game'=>(string)($pick['game'] ?? ''),
                'confidence_percent'=>isset($pick['score']) ? (string)round(((float)$pick['score']) * 100, 1) : '',
                'reason'=>(string)($pick['reason'] ?? ''),
                'status'=>(string)($pick['status'] ?? 'review'),
            ];
        }

        // Also expose obvious prose that the parser intentionally discarded.
        // This is diagnostic only and does not change entrant preferences.
        $pickedSegments = [];
        foreach ($picks as $pick) $pickedSegments[] = (string)($pick['segment'] ?? '');
        foreach (preferenceSegments($body, $data['games'] ?? []) as $segment) {
            if (!obviousNonChoice($segment)) continue;
            $rows[] = [
                'author'=>$author, 'original_comment'=>$body, 'pick_position'=>'',
                'original_text'=>$segment, 'proposed_game'=>'', 'confidence_percent'=>'',
                'reason'=>'obvious standalone courtesy/conversation text', 'status'=>'ignored_prose'
            ];
        }
    }
    return $rows;
}

function downloadParserDiagnostics(array $data): never {
    $filename = 'reddit-parser-diagnostics-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $headers = ['author','original_comment','pick_position','original_text','proposed_game','confidence_percent','reason','status'];
    fputcsv($out, $headers);
    foreach (parserDiagnosticRows($data) as $row) {
        fputcsv($out, array_map(fn($k)=>(string)($row[$k] ?? ''), $headers));
    }
    fclose($out);
    exit;
}

$error = null;
$stage = 'form';
$data = null;

if (isset($_GET['download']) && $_GET['download'] === 'parser-diagnostics') {
    if (empty($_SESSION['ranked_offline'])) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Review session expired. Import comments again.');
    }
    downloadParserDiagnostics($_SESSION['ranked_offline']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? 'analyze');

        if ($action === 'analyze') {
            $package = readGiveawayPackage();
            if ($package !== null) {
                $games = $package['games'];
                $comments = $package['comments'];
                $opUsername = $package['op_username'];
                $packageMeta = $package['meta'];
            } else {
                $games = parseGames(readGamesImport());
                if (!$games) throw new RuntimeException('Enter at least one game title.');
                $comments = parseDelimitedText(readImport());
                if (!$comments) throw new RuntimeException('No valid comments found. Expected username + comment columns.');
                $opUsername = (string)($_POST['op_username'] ?? '');
                $packageMeta = null;
            }

            $analysis = analyzeImportedComments($comments, $games, $opUsername);

            $_SESSION['ranked_offline'] = [
                'games'=>$games,
                'comments_count'=>count($comments),
                'analysis'=>$analysis,
                'package_meta'=>$packageMeta,
            ];
            $data = $_SESSION['ranked_offline'];
            $stage = 'review';
        } elseif ($action === 'draw') {
            if (empty($_SESSION['ranked_offline'])) {
                throw new RuntimeException('Review session expired. Import comments again.');
            }

            $data = $_SESSION['ranked_offline'];
            $users = $data['analysis']['users'];
            $decisionsJson = (string)($_POST['decisions_json'] ?? '{}');
            $decisions = json_decode($decisionsJson, true);
            if (!is_array($decisions)) {
                throw new RuntimeException('Could not read the reviewed game choices. Please return to the review screen and try again.');
            }

            $entrants = [];
            foreach ($users as $ui=>$u) {
                $prefs = [];
                foreach ($u['picks'] as $pi=>$pick) {
                    $hasPostedDecision = isset($decisions[$ui]) && is_array($decisions[$ui]) && array_key_exists($pi, $decisions[$ui]);

                    if ($hasPostedDecision) {
                        // The browser sends review rows and any confident rows the
                        // user manually changed. A review fragment may resolve to
                        // several ranked games, so preserve the posted order.
                        $decision = $decisions[$ui][$pi];
                        $choices = is_array($decision) ? $decision : [$decision];
                    } elseif (($pick['status'] ?? '') === 'confident' && !empty($pick['game'])) {
                        // Confident choices are authoritative server-side baseline
                        // data. They do NOT need to be round-tripped through the
                        // browser. This keeps 1,000+ entrant draws complete even if
                        // the review form only submits the small set of overrides.
                        $choices = [$pick['game']];
                    } else {
                        // Review rows are intentionally not guessed server-side.
                        // The review form always submits them, even when the user
                        // leaves the suggested choice unchanged. If one is missing,
                        // fail safely instead of silently dropping/reinterpreting it.
                        throw new RuntimeException('A manual-review choice was missing from the draw submission for u/' . $u['author'] . '. Please return to the review screen and try again.');
                    }

                    foreach ($choices as $choice) {
                        $choice = (string)$choice;
                        if ($choice !== '' && in_array($choice, $data['games'], true)) {
                            $prefs[] = $choice;
                        }
                    }
                }
                $prefs = dedupePreferenceGames($prefs);
                if ($prefs) {
                    $entrants[] = ['author'=>$u['author'],'preferences'=>$prefs];
                }
            }

            $result = allocateByRandomOrder($entrants, $data['games']);
            $data['entrants'] = $entrants;
            $data['result'] = $result;
            $data['drawn_at'] = date(DATE_RFC3339);
            unset($_SESSION['ranked_offline']);
            $stage = 'result';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $stage = 'form';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<style>
:root{color-scheme:light dark;--bg:#f4f5f7;--panel:#fff;--text:#202124;--muted:#667085;--border:#d0d5dd;--accent:#ff4500;--soft:#fff4ef;--bad:#b42318;--good:#16794b}
@media(prefers-color-scheme:dark){:root{--bg:#111418;--panel:#1b1f24;--text:#eef2f6;--muted:#a7b0bb;--border:#3a414a;--soft:#2a1b16;--bad:#ff8f87;--good:#65d6a0}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{max-width:1150px;margin:0 auto;padding:32px 18px 60px}h1{margin:0 0 6px;font-size:30px}h2{margin-top:0}.muted,.lead{color:var(--muted)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin:18px 0}
.card{border:1px solid var(--border);border-radius:11px;padding:15px;margin:10px 0}
label{display:block;font-weight:650;margin:12px 0 6px}input[type=text],textarea,select,input[type=file]{width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;background:transparent;color:inherit;font:inherit}
textarea{min-height:150px}select{min-width:260px}button,.button{border:0;border-radius:9px;background:var(--accent);color:#fff;padding:11px 17px;font-weight:700;font-size:15px;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-block}
.error{border-left:5px solid var(--bad)}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:13px;color:var(--muted)}
.comment{white-space:pre-wrap;background:rgba(127,127,127,.08);padding:10px;border-radius:8px;margin:7px 0}.small{font-size:13px}.winner{background:var(--soft);border-color:var(--accent)}.good{color:var(--good)}.bad{color:var(--bad)}
.review-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px}.review-controls button{margin-top:0}.clean-user.is-hidden{display:none}.pickrow{display:grid;grid-template-columns:55px minmax(180px,1fr) minmax(260px,1.2fr);gap:10px;align-items:start;margin:8px 0}.rank{font-weight:700}.multi-pick-list{display:flex;flex-direction:column;gap:7px}.multi-pick-item{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:6px;align-items:center}.multi-pick-actions{display:flex;gap:4px}.mini-btn{margin:0;padding:7px 9px;border-radius:7px;font-size:12px;background:var(--muted)}.add-game-btn{margin-top:7px;padding:8px 11px;font-size:13px;background:var(--good)}
@media(max-width:760px){.pickrow{grid-template-columns:1fr}.rank{margin-top:8px}}
</style>
</head>
<body><main>
<h1><?= h(APP_NAME) ?></h1>
<p class="lead">Import top-level Reddit comments, parse each entrant's ranked game choices, review the matches, then randomize users once and award each person their highest-ranked game still available.</p>

<?php if ($error): ?><div class="panel error"><strong>Could not continue:</strong> <?= h($error) ?></div><?php endif; ?>

<?php if ($stage === 'form'): ?>
<form method="post" enctype="multipart/form-data" class="panel" id="importForm">
<input type="hidden" name="action" value="analyze">
<textarea name="package_json" id="packageJsonDirect" hidden></textarea>
<h2>Import giveaway</h2>
<label>Giveaway package JSON — recommended</label>
<input type="file" name="package_file" accept=".json,application/json">
<p class="small muted">Use the single <code>reddit-giveaway-THREADID.json</code> file exported by Reddit Giveaway Extractor v3.4+, or use Extractor v3.5+ and click <strong>Open in GIVEAWAY PICKER</strong>. The package contains the verified game list, top-level comments, and thread metadata, including the original poster username.</p>

<details style="margin-top:18px"><summary><strong>Legacy/manual import options</strong></summary>
<label>Master game list — one game per line</label>
<textarea name="games" placeholder="Hades II&#10;Balatro&#10;DOOM: The Dark Ages"></textarea>
<label>— or upload the game-list TXT from the Reddit extractor —</label>
<input type="file" name="games_file" accept=".txt,text/plain">
<label>Your Reddit username (optional)</label>
<input type="text" name="op_username" placeholder="devoidzer0">
<label>Upload TOP-LEVEL comments CSV/TSV</label>
<input type="file" name="comments_file" accept=".csv,.tsv,.txt,text/csv,text/plain">
<label>— or paste username/comment data —</label>
<textarea name="comments_text" placeholder="author	comment&#10;UserOne	1. Hades II&#10;2. Balatro"></textarea>
</details>
<p class="small muted">The picker does not contact Reddit. It assumes the supplied comments are the entries you intend to include.</p>
<button type="submit">Import &amp; parse ranked picks</button>
</form>

<?php elseif ($stage === 'review'): ?>
<form method="post" id="reviewForm">
<input type="hidden" name="action" value="draw">
<input type="hidden" name="decisions_json" id="decisionsJson" value="{}">
<div class="panel">
<h2>Review parsed preferences</h2>
<?php
$autoCount=0; $reviewCount=0; $noPickUsers=0;
foreach ($data['analysis']['users'] as $summaryUser) {
    if (!$summaryUser['picks']) $noPickUsers++;
    foreach ($summaryUser['picks'] as $summaryPick) {
        if ($summaryPick['status']==='confident') $autoCount++; else $reviewCount++;
    }
}
?>
<p><?= count($data['analysis']['users']) ?> unique users found from <?= (int)$data['comments_count'] ?> imported comments.</p>
<?php if (!empty($data['package_meta'])): $pm=$data['package_meta']; ?>
<p class="small muted">Imported from giveaway package<?= !empty($pm['thread_id']) ? ' for thread ' . h($pm['thread_id']) : '' ?><?= !empty($pm['title']) ? ': ' . h($pm['title']) : '' ?><?= !empty($pm['extractor_version']) ? ' · extractor v' . h($pm['extractor_version']) : '' ?>.</p>
<?php endif; ?>

<p><strong><?= $autoCount ?></strong> choices matched confidently · <strong><?= $reviewCount ?></strong> need review · <strong><?= $noPickUsers ?></strong> users have no detected choices.</p>
<p class="muted">By default, only users who need attention are shown below: users with at least one review item or no detected choices. Confident matches remain preselected and are still included in the draw. Choose “Ignore this line” for text that is not actually a game choice. For a review line that contains more than one game, use “Add another game”; the selected games are inserted in the order shown before the entrant’s later picks.</p>
<div class="review-controls"><button type="button" id="toggleCleanUsers">Show all entrants</button><span class="small muted" id="filterStatus"></span></div>
<a class="button" href="<?= h($_SERVER['PHP_SELF']) ?>?download=parser-diagnostics">Download Parser Diagnostics CSV</a>
<p class="small muted">Includes confident and review items, no-choice users, and obvious prose intentionally ignored by the parser. No randomization occurs.</p>
</div>

<?php foreach ($data['analysis']['users'] as $ui=>$u): ?>
<?php $needsAttention = !$u['picks']; foreach ($u['picks'] as $attentionPick) { if ($attentionPick['status'] !== 'confident') { $needsAttention = true; break; } } ?>
<div class="panel entrant-panel <?= $needsAttention ? 'attention-user' : 'clean-user is-hidden' ?>">
<h2>u/<?= h($u['author']) ?></h2>
<div class="comment"><?= h($u['body']) ?></div>

<?php if (!$u['picks']): ?>
<p class="bad">No preference lines were detected. This user will not enter the draw unless a preference exists.</p>
<?php endif; ?>

<?php foreach ($u['picks'] as $pi=>$pick): ?>
<div class="pickrow">
<div class="rank">#<?= (int)$pick['position'] ?></div>
<div>
<strong><?= h($pick['segment']) ?></strong><br>
<span class="small muted"><?= h($pick['reason']) ?><?php if ($pick['score']): ?> · <?= number_format((float)$pick['score']*100, 0) ?>%<?php endif; ?></span>
</div>
<div>
<?php if ($pick['status'] === 'confident'): ?>
<select class="pick-choice" data-ui="<?= $ui ?>" data-pi="<?= $pi ?>" data-status="<?= h($pick['status']) ?>" data-default="<?= h((string)($pick['game'] ?? '')) ?>">
<option value="">Ignore this line</option>
<?php foreach ($data['games'] as $game): ?>
<option value="<?= h($game) ?>" <?= $pick['game']===$game?'selected':'' ?>><?= h($game) ?><?= $pick['game']===$game?' — suggested':'' ?></option>
<?php endforeach; ?>
</select>
<?php else: ?>
<div class="multi-pick-list" data-multi-pick data-ui="<?= $ui ?>" data-pi="<?= $pi ?>">
  <div class="multi-pick-item">
    <select class="pick-choice" data-ui="<?= $ui ?>" data-pi="<?= $pi ?>" data-status="<?= h($pick['status']) ?>" data-default="<?= h((string)($pick['game'] ?? '')) ?>">
      <option value="">Ignore this line</option>
      <?php foreach ($data['games'] as $game): ?>
      <option value="<?= h($game) ?>" <?= $pick['game']===$game?'selected':'' ?>><?= h($game) ?><?= $pick['game']===$game?' — suggested':'' ?></option>
      <?php endforeach; ?>
    </select>
    <div class="multi-pick-actions">
      <button type="button" class="mini-btn move-up" title="Move this resolved game earlier">↑</button>
      <button type="button" class="mini-btn move-down" title="Move this resolved game later">↓</button>
    </div>
  </div>
</div>
<button type="button" class="add-game-btn" data-add-game>Add another game</button>
<p class="small muted">If this one review fragment contains multiple choices, add them here in the entrant’s intended order.</p>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="panel">
<p><strong>No randomization has happened yet.</strong> Clicking below randomizes all entrants once, then processes them in that fixed order.</p>
<button type="submit">Randomize users &amp; assign games</button>
</div>
</form>

<?php elseif ($stage === 'result'): $r=$data['result']; ?>
<div class="panel">
<h2>Assignments</h2>
<table>
<thead><tr><th>Random position</th><th>User</th><th>Game awarded</th><th>Preference</th></tr></thead>
<tbody>
<?php foreach ($r['assignments'] as $a): ?>
<tr>
<td>#<?= (int)$a['draw_position'] ?></td>
<td><strong>u/<?= h($a['author']) ?></strong></td>
<td><?= h($a['game']) ?></td>
<td>#<?= (int)$a['preference_rank'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php if (!$r['assignments']): ?><p class="muted">No games were assigned.</p><?php endif; ?>
<p class="small muted">Drawn <?= h($data['drawn_at']) ?> using PHP <code>random_int()</code>. The random order was generated once and never reshuffled.</p>
</div>

<div class="panel">
<h2>Skipped users encountered before allocation ended</h2>
<?php if (!$r['skipped']): ?><p class="muted">None.</p><?php else: ?>
<table><thead><tr><th>Position</th><th>User</th><th>Reason</th><th>Preferences</th></tr></thead><tbody>
<?php foreach ($r['skipped'] as $s): ?>
<tr><td>#<?= (int)$s['draw_position'] ?></td><td>u/<?= h($s['author']) ?></td><td><?= h($s['reason']) ?></td><td><?= h(implode(' → ', $s['preferences'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<div class="panel">
<h2>Unassigned games</h2>
<?php if (!$r['unassigned_games']): ?><p class="good"><strong>All games were assigned.</strong></p>
<?php else: ?><p><?= h(implode(', ', $r['unassigned_games'])) ?></p><?php endif; ?>
</div>

<div class="panel">
<h2>Draw audit</h2>
<p><strong><?= count($r['random_order']) ?></strong> eligible entrants were randomized in one complete order.</p>
<p class="small muted"><strong><?= count($r['assignments']) + count($r['skipped']) ?></strong> entrants were processed before the allocation stopped<?php if (!$r['unassigned_games']): ?> because all games had been assigned<?php else: ?> because the randomized entrant list was exhausted<?php endif; ?>. The full randomized order below still contains every eligible entrant.</p>
</div>

<div class="panel">
<h2>Full randomized entrant order</h2>
<ol>
<?php foreach ($r['random_order'] as $u): ?>
<li><strong>u/<?= h($u['author']) ?></strong> — <?= h(implode(' → ', $u['preferences'])) ?></li>
<?php endforeach; ?>
</ol>
</div>
<div class="panel">
<h2>Publishable results file</h2>
<p class="muted">Download a standalone HTML page containing the winners and their awarded games. Upload that file to your web host and link to it from the Reddit giveaway thread.</p>
<button type="button" id="downloadResultsHtml">Download Results HTML</button>
</div>
<a class="button" href="<?= h($_SERVER['PHP_SELF']) ?>">Start another giveaway</a>
<?php endif; ?>
</main><script>
(function(){
  const gameOptions = <?= json_encode(array_values($data['games'] ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const publicResults = <?= json_encode(($stage === 'result' && isset($data['result'])) ? [
    'drawn_at'=>$data['drawn_at'] ?? '',
    'assignments'=>$data['result']['assignments'] ?? [],
    'unassigned_games'=>$data['result']['unassigned_games'] ?? [],
  ] : null, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  function optionHtml(selected='') {
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    let out = '<option value="">Ignore this line</option>';
    for (const game of gameOptions) {
      out += `<option value="${esc(game)}"${game===selected?' selected':''}>${esc(game)}</option>`;
    }
    return out;
  }

  function updateButtons(list) {
    const items=[...list.querySelectorAll('.multi-pick-item')];
    items.forEach((item,i)=>{
      const up=item.querySelector('.move-up');
      const down=item.querySelector('.move-down');
      if(up) up.disabled=(i===0);
      if(down) down.disabled=(i===items.length-1);
      let remove=item.querySelector('.remove-pick');
      if(items.length>1 && !remove){
        remove=document.createElement('button');
        remove.type='button'; remove.className='mini-btn remove-pick'; remove.title='Remove this added game'; remove.textContent='×';
        item.querySelector('.multi-pick-actions').appendChild(remove);
      }
      if(items.length===1 && remove) remove.remove();
    });
  }

  document.addEventListener('click', e=>{
    const add=e.target.closest('[data-add-game]');
    if(add){
      const list=add.previousElementSibling;
      if(!list || !list.matches('[data-multi-pick]')) return;
      const item=document.createElement('div');
      item.className='multi-pick-item';
      item.innerHTML=`<select class="pick-choice" data-ui="${list.dataset.ui}" data-pi="${list.dataset.pi}" data-status="review" data-default="">${optionHtml('')}</select><div class="multi-pick-actions"><button type="button" class="mini-btn move-up" title="Move this resolved game earlier">↑</button><button type="button" class="mini-btn move-down" title="Move this resolved game later">↓</button><button type="button" class="mini-btn remove-pick" title="Remove this added game">×</button></div>`;
      list.appendChild(item);
      updateButtons(list);
      item.querySelector('select').focus();
      return;
    }
    const item=e.target.closest('.multi-pick-item');
    if(!item) return;
    const list=item.closest('[data-multi-pick]');
    if(e.target.closest('.move-up')){
      const prev=item.previousElementSibling;
      if(prev) list.insertBefore(item,prev);
    } else if(e.target.closest('.move-down')){
      const next=item.nextElementSibling;
      if(next) list.insertBefore(next,item);
    } else if(e.target.closest('.remove-pick')){
      item.remove();
    } else return;
    updateButtons(list);
  });

  document.querySelectorAll('[data-multi-pick]').forEach(updateButtons);

  function escapeHtmlText(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  }

  const downloadResultsBtn=document.getElementById('downloadResultsHtml');
  if(downloadResultsBtn && publicResults){
    downloadResultsBtn.addEventListener('click', ()=>{
      const rows=(publicResults.assignments||[]).map(a => `
        <tr><td>${escapeHtmlText(a.game)}</td><td><strong>u/${escapeHtmlText(a.author)}</strong></td><td>#${Number(a.preference_rank)||''}</td></tr>`).join('');
      const unassigned=(publicResults.unassigned_games||[]);
      const unassignedHtml=unassigned.length
        ? `<p><strong>Unassigned games:</strong> ${unassigned.map(escapeHtmlText).join(', ')}</p>`
        : '<p><strong>All giveaway games were assigned.</strong></p>';
      const drawn=escapeHtmlText(publicResults.drawn_at || '');
      const html='<!doctype html>\n'+`<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reddit Giveaway Results</title><style>body{font:16px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:900px;margin:40px auto;padding:0 18px;color:#202124;background:#fff}h1{margin-bottom:6px}.muted{color:#667085}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #ddd}th{font-size:14px;color:#667085}@media(prefers-color-scheme:dark){body{color:#eee;background:#111418}.muted,th{color:#aaa}th,td{border-color:#444}}</style></head><body><h1>Reddit Giveaway Results</h1><p class="muted">Draw completed ${drawn}. Entrants were randomized once and each winner received their highest-ranked game still available.</p><table><thead><tr><th>Game</th><th>Winner</th><th>Preference</th></tr></thead><tbody>${rows}</tbody></table>${unassignedHtml}<p class="muted">Generated by Reddit Giveaway Picker — Ranked Random Order (Offline).</p></body></html>`;
      const blob=new Blob([html],{type:'text/html;charset=utf-8'});
      const url=URL.createObjectURL(blob);
      const a=document.createElement('a');
      const stamp=(publicResults.drawn_at||new Date().toISOString()).replace(/[:]/g,'-').replace(/[^0-9T-]/g,'').slice(0,19);
      a.href=url; a.download=`reddit-giveaway-results-${stamp || 'results'}.html`;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
  }

  const reviewForm=document.getElementById('reviewForm');
  if(reviewForm){
    reviewForm.addEventListener('submit', e=>{
      // Send only manual-review rows plus confident rows that the user changed.
      // All untouched confident choices remain stored in the PHP session and are
      // reconstructed server-side. This avoids round-tripping thousands of picks.
      const grouped={};
      document.querySelectorAll('select.pick-choice').forEach(sel=>{
        const ui=sel.dataset.ui, pi=sel.dataset.pi;
        if(ui===undefined || pi===undefined) return;
        const key=`${ui}:${pi}`;
        if(!grouped[key]) grouped[key]={ui,pi,status:sel.dataset.status||'',defaultValue:sel.dataset.default||'',values:[]};
        grouped[key].values.push(sel.value || '');
      });

      const decisions={};
      Object.values(grouped).forEach(group=>{
        const isReview=group.status==='review';
        const changed=group.values.length!==1 || group.values[0]!==group.defaultValue;
        if(!isReview && !changed) return;
        if(!decisions[group.ui]) decisions[group.ui]={};
        decisions[group.ui][group.pi]=group.values;
      });

      const field=document.getElementById('decisionsJson');
      if(field) field.value=JSON.stringify(decisions);
    });
  }
})();
(function(){
  const btn=document.getElementById('toggleCleanUsers');
  if(!btn) return;
  const clean=[...document.querySelectorAll('.clean-user')];
  const attention=[...document.querySelectorAll('.attention-user')];
  const status=document.getElementById('filterStatus');
  let showingAll=false;
  function render(){
    clean.forEach(el=>el.classList.toggle('is-hidden',!showingAll));
    btn.textContent=showingAll?'Show problems only':'Show all entrants';
    if(status) status.textContent=showingAll ? `Showing all ${clean.length+attention.length} entrants.` : `Showing ${attention.length} entrant(s) needing attention; ${clean.length} confident entrant(s) hidden.`;
  }
  btn.addEventListener('click',()=>{showingAll=!showingAll;render();});
  render();
})();
</script>
</body></html>
