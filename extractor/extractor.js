(() => {
  'use strict';

  const VERSION = '3.5.0';
  const PICKER_URL = 'http://localhost/reddit-giveaway-picker-offline/';
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

  function cleanText(s) {
    return (s || '')
      .replace(/\u00a0/g, ' ')
      .replace(/\r\n?/g, '\n')
      .replace(/[ \t]+\n/g, '\n')
      .replace(/\n[ \t]+/g, '\n')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function csvEscape(value) {
    return '"' + String(value ?? '').replace(/"/g, '""') + '"';
  }

  function downloadCsv(rows, filename) {
    const lines = [
      ['author', 'comment'].map(csvEscape).join(',')
    ];
    for (const row of rows) {
      lines.push([row.author, row.comment].map(csvEscape).join(','));
    }
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], {
      type: 'text/csv;charset=utf-8'
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function downloadText(text, filename) {
    const blob = new Blob(['\uFEFF' + String(text || '')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function downloadJson(data, filename) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1500);
  }

  function originalPostBody() {
    // On Old Reddit the submission is the non-comment .thing.link at the top.
    const post = document.querySelector('.thing.link[data-fullname^="t3_"], .thing.link');
    return post?.querySelector(':scope > .entry .usertext .usertext-body .md, :scope > .entry .usertext-body .md') || null;
  }

  function stripBracketedGameNote(raw) {
    // Optional notes in future giveaway posts should be written at the END in
    // square brackets, e.g. "Diablo IV [requires Battle.net]". They are useful
    // to readers but are not part of the title used by the picker.
    return String(raw || '').replace(/\s*\[[^\]\n]+\]\s*$/g, '').trim();
  }

  function cleanGameTitle(raw) {
    let s = cleanText(raw).replace(/\n+/g, ' ');
    s = s.replace(/^\s*\d{1,3}\s*[.)\]:-]\s*/, '');
    s = s.replace(/\bCONTACT\s+ME\b\s*!?/ig, ' ');
    s = s.replace(/(?:^|\s)[\[(]?u\/[A-Za-z0-9_-]+[\])]?/ig, ' ');
    s = s.replace(/\s+(?:[-–—|:;,]\s*)+$/g, '');
    s = s.replace(/(?:\s+[-–—|]\s*){2,}/g, ' - ');
    s = stripBracketedGameNote(s);
    return s.replace(/\s{2,}/g, ' ').trim();
  }

  function finalizeGames(rawItems) {
    const seen = new Set();
    const games = [];
    for (const raw of rawItems) {
      const title = cleanGameTitle(raw);
      if (!title) continue;
      const key = title.toLowerCase();
      if (seen.has(key)) continue;
      seen.add(key);
      games.push(title);
    }
    return games;
  }

  function parseGameListFromPost() {
    const body = originalPostBody();
    if (!body) return { games: [], source: 'Original post body not found.' };

    const text = cleanText(body.innerText || body.textContent || '');
    const lines = text.split('\n').map(line => line.trim());

    // Preferred format for NEW giveaways. This makes the boundaries explicit
    // while allowing ordinary unnumbered game titles and optional [notes].
    const startIndex = lines.findIndex(line => /^\[GAMES\]$/i.test(line));
    if (startIndex >= 0) {
      const relativeEnd = lines.slice(startIndex + 1).findIndex(line => /^\[\/GAMES\]$/i.test(line));
      if (relativeEnd >= 0) {
        const endIndex = startIndex + 1 + relativeEnd;
        const rawItems = lines.slice(startIndex + 1, endIndex).filter(Boolean);
        const games = finalizeGames(rawItems);
        return {
          games,
          source: `[GAMES] block in the original post (${rawItems.length} lines; trailing [notes] ignored)`
        };
      }
      return { games: [], source: 'Found [GAMES] but no closing [/GAMES] marker.' };
    }

    const nonblank = lines.map((line, index) => ({ line, index })).filter(x => x.line);

    // Legacy completed giveaways: Game Title: u/winner OR Game Title: TBD.
    // Matching from the end preserves colons that belong inside a title.
    const legacyCandidates = [];
    for (const {line, index} of nonblank) {
      let m = line.match(/^(.*?)\s*:\s*u\/[A-Za-z0-9_-]+\b(?:\s+CONTACT\s+ME\s*!?)?\s*$/i);
      if (m) {
        const title = cleanGameTitle(m[1]);
        if (title) legacyCandidates.push({ index, title });
        continue;
      }
      m = line.match(/^(.*?)\s*:\s*TBD\s*(?:CONTACT\s+ME\s*!?)?\s*$/i);
      if (m) {
        const title = cleanGameTitle(m[1]);
        if (title) legacyCandidates.push({ index, title });
      }
    }

    // Prefer the largest reasonably contiguous legacy block.
    let bestBlock = [];
    let current = [];
    for (const item of legacyCandidates) {
      if (!current.length || item.index - current[current.length - 1].index <= 3) current.push(item);
      else {
        if (current.length > bestBlock.length) bestBlock = current;
        current = [item];
      }
    }
    if (current.length > bestBlock.length) bestBlock = current;

    if (bestBlock.length >= 2) {
      const games = finalizeGames(bestBlock.map(x => x.title));
      return { games, source: `legacy completed-giveaway lines in the original post (${games.length} entries; winner/TBD annotations removed)` };
    }

    // Older/alternate active giveaways may use actual ordered lists or numbered text.
    const orderedLists = [...body.querySelectorAll('ol')]
      .map(ol => ({ items: [...ol.children].filter(el => el.tagName === 'LI') }))
      .filter(x => x.items.length >= 2)
      .sort((a, b) => b.items.length - a.items.length);

    if (orderedLists.length) {
      const rawItems = orderedLists[0].items.map(li => li.innerText || li.textContent || '');
      return { games: finalizeGames(rawItems), source: `largest numbered list in the original post (${rawItems.length} entries)` };
    }

    const rawItems = nonblank
      .filter(x => /^\s*\d{1,3}\s*[.)\]:-]\s*\S/.test(x.line))
      .map(x => x.line.replace(/^\s*\d{1,3}\s*[.)\]:-]\s*/, ''));
    return { games: finalizeGames(rawItems), source: `numbered text lines in the original post (${rawItems.length} entries)` };
  }

  function gameFilename() {
    const m = location.pathname.match(/\/comments\/([a-z0-9]+)/i);
    const id = m ? m[1] : 'thread';
    return `reddit-games-${id}.txt`;
  }


  function threadId() {
    const m = location.pathname.match(/\/comments\/([a-z0-9]+)/i);
    return m ? m[1] : 'thread';
  }

  function packageFilename() {
    return `reddit-giveaway-${threadId()}.json`;
  }

  function threadMetadata() {
    const post = document.querySelector('.thing.link[data-fullname^="t3_"], .thing.link');
    const title = cleanText(post?.querySelector('a.title')?.textContent || '');
    const subreddit = cleanText(
      post?.getAttribute('data-subreddit') ||
      post?.querySelector('.subreddit')?.textContent || ''
    ).replace(/^r\//i, '');
    const opAuthor = cleanText(
      post?.getAttribute('data-author') ||
      post?.querySelector('.tagline .author')?.textContent || ''
    ).replace(/^u\//i, '');
    return {
      id: threadId(),
      title,
      subreddit,
      op_author: opAuthor,
      source_url: location.href
    };
  }

  function buildGiveawayPackage(gameArea) {
    const games = gameArea.value.split(/\r?\n/).map(cleanText).filter(Boolean);
    const all = collect();
    const top = all.filter(row => row.depth === 0 || row.depth === null);
    const replies = all.filter(row => Number.isFinite(row.depth) && row.depth > 0);
    return {
      schema: 'reddit-giveaway-package',
      schema_version: 1,
      created_at: new Date().toISOString(),
      extractor_version: VERSION,
      thread: threadMetadata(),
      games,
      comments: top.map(row => ({ author: row.author, comment: row.comment })),
      stats: {
        loaded_comments: all.length,
        top_level_comments: top.length,
        replies: replies.length
      }
    };
  }

  function newRedditComments() {
    return [...document.querySelectorAll('shreddit-comment')].map(el => {
      const author =
        el.getAttribute('author') ||
        el.querySelector('a[href^="/user/"]')?.textContent?.trim() ||
        '';

      const bodyEl =
        el.querySelector(':scope > [slot="comment"]') ||
        el.querySelector(':scope > [id$="-comment-rtjson-content"]') ||
        el.querySelector('[id$="-comment-rtjson-content"]');

      let body = cleanText(bodyEl?.innerText || bodyEl?.textContent || '');

      if (!body) {
        const candidates = [...el.children].filter(child => {
          const slot = child.getAttribute?.('slot');
          const id = child.id || '';
          return slot === 'comment' || /comment.*content/i.test(id);
        });
        body = cleanText(candidates[0]?.innerText || candidates[0]?.textContent || '');
      }

      const depthRaw = el.getAttribute('depth');
      const depth = depthRaw !== null && depthRaw !== '' ? parseInt(depthRaw, 10) : null;

      return {
        author: cleanText(author).replace(/^u\//i, ''),
        comment: body,
        depth: Number.isFinite(depth) ? depth : null
      };
    });
  }

  function oldRedditComments() {
    return [...document.querySelectorAll('.comment[data-author], .thing.comment')].map(el => {
      const author =
        el.getAttribute('data-author') ||
        el.querySelector(':scope > .entry .tagline .author')?.textContent?.trim() ||
        '';

      const bodyEl =
        el.querySelector(':scope > .entry .usertext .usertext-body .md') ||
        el.querySelector(':scope > .entry .usertext-body .md');

      // Count actual ancestor comment nodes. This is more reliable on Old Reddit
      // than treating every nested comment as simply depth 1.
      let depth = 0;
      let ancestor = el.parentElement;
      while (ancestor) {
        if (ancestor.matches?.('.thing.comment, .comment[data-author]')) depth++;
        ancestor = ancestor.parentElement;
      }
      return {
        author: cleanText(author).replace(/^u\//i, ''),
        comment: cleanText(bodyEl?.innerText || bodyEl?.textContent || ''),
        depth
      };
    });
  }

  function collect() {
    let rows = newRedditComments();
    if (!rows.length) rows = oldRedditComments();

    const seen = new Set();
    return rows.filter(row => {
      if (!row.author || row.author === '[deleted]' || !row.comment) return false;
      const key = row.author.toLowerCase() + '\n' + row.comment;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function filename(type) {
    const m = location.pathname.match(/\/comments\/([a-z0-9]+)/i);
    const id = m ? m[1] : 'thread';
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    return `reddit-comments-${id}-${type}-${stamp}.csv`;
  }

  function oldRedditExpansionButtons() {
    // Old Reddit exposes AJAX expansion links as .morecomments/.deepthread controls.
    // They do not need to be scrolled into view before click().
    const selectors = [
      '.morecomments a.button',
      '.morecomments a',
      '.deepthread a.button',
      '.deepthread a'
    ];
    const out = [];
    for (const selector of selectors) {
      for (const el of document.querySelectorAll(selector)) {
        if (!(el instanceof HTMLElement)) continue;
        if (!el.isConnected) continue;
        const text = cleanText(el.textContent || el.getAttribute('title') || '').toLowerCase();
        if (!text) continue;
        if (/more comments|more replies|continue this thread|load more/.test(text)) out.push(el);
      }
    }
    return [...new Set(out)];
  }

  function waitForDomChange(timeoutMs = 4500) {
    return new Promise(resolve => {
      let done = false;
      const finish = changed => {
        if (done) return;
        done = true;
        observer.disconnect();
        clearTimeout(timer);
        resolve(changed);
      };
      const observer = new MutationObserver(() => finish(true));
      observer.observe(document.querySelector('.commentarea') || document.body, {
        childList: true,
        subtree: true
      });
      const timer = setTimeout(() => finish(false), timeoutMs);
    });
  }

  async function clickOldRedditBatch(statusFn, stopFn, batchSize = 4) {
    const buttons = oldRedditExpansionButtons().slice(0, batchSize);
    let clicked = 0;

    for (const btn of buttons) {
      if (stopFn()) break;
      if (!btn.isConnected) continue;
      try {
        const before = collect().length;
        const changed = waitForDomChange();
        btn.click();
        clicked++;
        statusFn?.(`Opening comment batch ${clicked}/${buttons.length}…`);
        await Promise.race([changed, sleep(1800)]);
        // Give Old Reddit a moment to finish inserting the returned comment nodes.
        if (collect().length === before) await sleep(500);
      } catch (_) {}
    }
    return clicked;
  }

  async function autoLoadAll(statusFn, stopFn) {
    let previousCount = collect().length;
    let stablePasses = 0;
    let pass = 0;
    const maxPasses = 100;

    while (pass < maxPasses && !stopFn()) {
      pass++;
      const buttonsBefore = oldRedditExpansionButtons().length;
      statusFn(`Pass ${pass}: ${previousCount} comments loaded; ${buttonsBefore} expansion control(s) available.`);

      if (!buttonsBefore) {
        stablePasses++;
        if (stablePasses >= 2) break;
        await sleep(900);
        continue;
      }

      const clicked = await clickOldRedditBatch(statusFn, stopFn, 4);
      await sleep(clicked ? 700 : 1000);

      const currentCount = collect().length;
      const remainingButtons = oldRedditExpansionButtons().length;
      statusFn(`Pass ${pass}: ${currentCount} comments loaded; ${remainingButtons} expansion control(s) remain.`);

      if (currentCount > previousCount || remainingButtons < buttonsBefore) {
        stablePasses = 0;
      } else {
        stablePasses++;
      }
      previousCount = currentCount;

      if (stablePasses >= 5) break;
    }

    return {
      count: collect().length,
      passes: pass,
      remainingButtons: oldRedditExpansionButtons().length
    };
  }

  function oldRedditDiagnostics() {
    const rawCommentEls = [...document.querySelectorAll('.thing.comment')];
    const rows = collect();
    const top = rows.filter(row => row.depth === 0);
    const replies = rows.filter(row => Number.isFinite(row.depth) && row.depth > 0);
    const unknownDepth = rows.filter(row => row.depth === null).length;

    const moreComments = [...document.querySelectorAll('.morecomments a')]
      .filter(el => /more comments|load more/i.test(cleanText(el.textContent || el.title || ''))).length;
    const moreReplies = [...document.querySelectorAll('.morecomments a')]
      .filter(el => /more replies/i.test(cleanText(el.textContent || el.title || ''))).length;
    const deepThreads = [...document.querySelectorAll('.deepthread a')]
      .filter(el => /continue this thread|load more/i.test(cleanText(el.textContent || el.title || ''))).length;

    // Old Reddit marks AJAX loaders with .loading in several places. Restrict this
    // diagnostic to the comment area so unrelated page activity is not counted.
    const area = document.querySelector('.commentarea') || document;
    const busy = [...area.querySelectorAll('.loading, .morecomments .loader, .deepthread .loader')]
      .filter(el => {
        const style = getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden';
      }).length;

    return {
      rawDomComments: rawCommentEls.length,
      extractedComments: rows.length,
      topLevel: top.length,
      replies: replies.length,
      unknownDepth,
      moreComments,
      moreReplies,
      deepThreads,
      busy,
      expansionTotal: oldRedditExpansionButtons().length
    };
  }

  function diagnosticHtml(d, passes = null) {
    const complete = d.expansionTotal === 0 && d.busy === 0;
    return (
      `<div style="margin-top:8px;padding:9px;background:#f4f5f7;border-radius:8px;color:#333">` +
      `<strong>Loading ${complete ? 'complete' : 'diagnostics'}</strong>${passes !== null ? ` — ${passes} pass(es)` : ''}<br>` +
      `${d.rawDomComments} raw Old Reddit comment elements<br>` +
      `${d.extractedComments} extractable comments<br>` +
      `${d.topLevel} top-level comments<br>` +
      `${d.replies} replies${d.unknownDepth ? `; ${d.unknownDepth} unknown depth` : ''}<br><br>` +
      `More-comments controls remaining: ${d.moreComments}<br>` +
      `More-replies controls remaining: ${d.moreReplies}<br>` +
      `Continue-thread controls remaining: ${d.deepThreads}<br>` +
      `Loading controls still busy: ${d.busy}<br><br>` +
      `<strong>${complete ? 'Old Reddit appears fully expanded.' : 'Old Reddit may not be fully expanded yet.'}</strong>` +
      `</div>`
    );
  }

  function removePanel() {
    document.getElementById('rgce-panel')?.remove();
  }

  function buildPanel() {
    removePanel();

    let stopRequested = false;
    let loading = false;

    const panel = document.createElement('div');
    panel.id = 'rgce-panel';
    panel.style.cssText = [
      'position:fixed','z-index:2147483647','right:20px','top:20px',
      'width:min(450px,calc(100vw - 40px))','background:#fff','color:#111',
      'border:1px solid #bbb','border-radius:12px','padding:16px',
      'box-sizing:border-box','max-height:calc(100vh - 40px)','overflow-y:auto',
      'overscroll-behavior:contain','box-shadow:0 8px 30px rgba(0,0,0,.28)',
      'font:14px/1.4 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif'
    ].join(';');

    const title = document.createElement('div');
    title.textContent = 'Reddit Giveaway Extractor';
    title.style.cssText = 'font-size:18px;font-weight:700;margin-bottom:8px';

    const status = document.createElement('div');
    status.style.cssText = 'margin-bottom:10px';

    function refreshStatus(extra = '', showDiagnostics = false, passes = null) {
      const all = collect();
      const top = all.filter(row => row.depth === 0 || row.depth === null);
      const replies = all.filter(row => Number.isFinite(row.depth) && row.depth > 0);
      status.innerHTML =
        `<strong>${all.length}</strong> loaded comments found.<br>` +
        `<strong>${top.length}</strong> appear to be top-level comments.<br>` +
        `<strong>${replies.length}</strong> appear to be replies.` +
        (extra ? `<div style="margin-top:7px;color:#555">${extra}</div>` : '') +
        (showDiagnostics ? diagnosticHtml(oldRedditDiagnostics(), passes) : '');
    }

    refreshStatus();

    const note = document.createElement('div');
    note.textContent =
      'Designed for Old Reddit. Auto-load expands comments without repeated scrolling. v3.5 can either download one giveaway package or open Giveaway Picker directly with the package already loaded.';
    note.style.cssText =
      'background:#f4f5f7;border-radius:8px;padding:9px;margin-bottom:12px;color:#444';

    const gameParse = parseGameListFromPost();
    const gameBox = document.createElement('div');
    gameBox.style.cssText = 'background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:9px;margin-bottom:12px;color:#444';

    const gameLabel = document.createElement('div');
    gameLabel.innerHTML = `<strong>Games parsed from original post: ${gameParse.games.length}</strong><br><span style="font-size:12px">${gameParse.source}</span>`;

    const gameArea = document.createElement('textarea');
    gameArea.value = gameParse.games.join('\n');
    gameArea.spellcheck = false;
    gameArea.style.cssText = 'box-sizing:border-box;width:100%;height:120px;margin-top:7px;padding:7px;border:1px solid #ccc;border-radius:6px;font:12px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace;resize:vertical';

    const gameHint = document.createElement('div');
    gameHint.textContent = 'Verify/edit this list if needed. One game per line. Your edits are included in the giveaway package.';
    gameHint.style.cssText = 'font-size:11px;color:#666;margin-top:5px';
    gameBox.append(gameLabel, gameArea, gameHint);

    function makeButton(label, background, onClick) {
      const b = document.createElement('button');
      b.textContent = label;
      b.type = 'button';
      b.style.cssText =
        `display:block;width:100%;border:0;border-radius:8px;padding:10px 12px;` +
        `margin:7px 0;background:${background};color:#fff;font-weight:700;cursor:pointer`;
      b.onclick = onClick;
      return b;
    }

    const loadBtn = makeButton(
      'Auto-load ALL comments (Old Reddit)',
      '#2f6f9f',
      async () => {
        if (loading) return;
        loading = true;
        stopRequested = false;
        loadBtn.disabled = true;
        stopBtn.style.display = 'block';

        const result = await autoLoadAll(
          msg => refreshStatus(msg),
          () => stopRequested
        );

        loading = false;
        loadBtn.disabled = false;
        stopBtn.style.display = 'none';

        refreshStatus(
          result.remainingButtons
            ? `${result.remainingButtons} expansion control(s) still appear visible.`
            : 'No obvious expansion controls remain.',
          true,
          result.passes
        );
      }
    );

    const stopBtn = makeButton(
      'Stop auto-load',
      '#a33',
      () => { stopRequested = true; }
    );
    stopBtn.style.display = 'none';

    function validatedPackage() {
      const pkg = buildGiveawayPackage(gameArea);
      if (!pkg.games.length) {
        alert('No games are currently listed. Verify the game list first.');
        return null;
      }
      if (!pkg.comments.length) {
        alert('No top-level comments were found. Auto-load the comments first.');
        return null;
      }
      return pkg;
    }

    function openPackageInPicker(pkg) {
      const targetName = 'redditGiveawayPicker';
      const pickerWindow = window.open('', targetName);
      if (!pickerWindow) {
        alert('The browser blocked the Giveaway Picker tab. Allow pop-ups for Old Reddit and try again.');
        return;
      }

      const form = document.createElement('form');
      form.method = 'POST';
      form.action = PICKER_URL;
      form.target = targetName;
      form.enctype = 'multipart/form-data';
      form.style.display = 'none';

      const action = document.createElement('input');
      action.type = 'hidden';
      action.name = 'action';
      action.value = 'analyze';

      const payload = document.createElement('textarea');
      payload.name = 'package_json';
      payload.value = JSON.stringify(pkg);

      form.append(action, payload);
      document.body.appendChild(form);
      form.submit();
      form.remove();
    }

    const openPickerBtn = makeButton(
      'Open in GIVEAWAY PICKER',
      '#0f766e',
      () => {
        const pkg = validatedPackage();
        if (pkg) openPackageInPicker(pkg);
      }
    );

    const packageBtn = makeButton(
      'Download GIVEAWAY PACKAGE (.json)',
      '#15803d',
      () => {
        const pkg = validatedPackage();
        if (pkg) downloadJson(pkg, packageFilename());
      }
    );

    const gameBtn = makeButton(
      'Download parsed GAME LIST',
      '#7c3aed',
      () => {
        const games = gameArea.value.split(/\r?\n/).map(cleanText).filter(Boolean);
        if (!games.length) return alert('No games are currently listed.');
        downloadText(games.join('\r\n') + '\r\n', gameFilename());
      }
    );

    const topBtn = makeButton(
      'Download TOP-LEVEL comments CSV',
      '#ff4500',
      () => {
        const rows = collect().filter(row => row.depth === 0 || row.depth === null);
        if (!rows.length) return alert('No top-level comments were found.');
        downloadCsv(rows, filename('top-level'));
      }
    );

    const allBtn = makeButton(
      'Download ALL loaded comments CSV',
      '#555',
      () => {
        const rows = collect();
        if (!rows.length) return alert('No comments were found.');
        downloadCsv(rows, filename('all'));
      }
    );

    const refreshBtn = makeButton('Refresh count + diagnostics', '#777', () => refreshStatus('', true));

    const close = document.createElement('button');
    close.textContent = 'Close';
    close.type = 'button';
    close.style.cssText =
      'display:block;margin:10px auto 0;border:0;background:transparent;color:#555;text-decoration:underline;cursor:pointer';
    close.onclick = () => {
      stopRequested = true;
      removePanel();
    };

    const footer = document.createElement('div');
    footer.textContent = `v${VERSION} • Runs locally in this page; no Reddit API calls.`;
    footer.style.cssText = 'font-size:11px;color:#777;margin-top:10px;text-align:center';

    panel.append(title, status, note, gameBox, loadBtn, stopBtn, openPickerBtn, packageBtn, gameBtn, topBtn, allBtn, refreshBtn, close, footer);
    document.body.appendChild(panel);
  }

  const isReddit = /(^|\.)reddit\.com$/i.test(location.hostname);
  const isOldReddit = /^old\.reddit\.com$/i.test(location.hostname);
  const isThread = /\/comments\/[a-z0-9]+/i.test(location.pathname);

  if (!isReddit || !isThread) {
    alert('Open a Reddit comments thread first, then run the extractor.');
    return;
  }

  if (!isOldReddit) {
    const oldUrl = new URL(location.href);
    oldUrl.hostname = 'old.reddit.com';
    if (confirm('Version 3.4 is designed for Old Reddit so it can access far more comments. Open this thread in Old Reddit now?')) {
      location.href = oldUrl.href;
    }
    return;
  }

  buildPanel();
})();