<?php
// Shared chrome for the static top-level pages (Home, Projects, About, Contact, Themes).
// Wears one of the suite's THEMES (lib/auth.php), chosen per browser by the `sitetheme`
// cookie from /themepicker/ — midnight (the original #111/#eee/#34d399 look) by default.
// The cookie only dresses these pages; the apps keep their own per-user theme prefs.

require_once __DIR__ . '/auth.php';   // THEMES / theme_vars()

/** The theme these public pages wear: the sitetheme cookie when it names a real one. */
function site_theme(): string {
  $t = (string) ($_COOKIE['sitetheme'] ?? '');
  return isset(THEMES[$t]) ? $t : 'midnight';
}

function site_nav($active) {
  $links = ['' => 'Home', 'projects' => 'Projects', 'about' => 'About', 'contact' => 'Contact', 'themepicker' => 'Themes'];
  $out = '<nav class="sitenav">';
  foreach ($links as $slug => $label) {
    $href = $slug === '' ? '/' : '/' . $slug . '/';
    $cls = $slug === $active ? ' class="on"' : '';
    $out .= '<a href="' . $href . '"' . $cls . '>' . $label . '</a>';
  }
  $out .= '</nav>';
  // The same nav as a dropdown for phone widths — a <details>, so no JS: the summary
  // wears the current page's name and the open menu lists every page. CSS swaps which
  // of the two shows; tapping a link navigates, which is also what closes it.
  $current = $links[$active] ?? 'Home';
  $out .= '<details class="sitenav-dd"><summary>' . $current . ' <span class="caret">&#9662;</span></summary><div class="menu">';
  foreach ($links as $slug => $label) {
    $href = $slug === '' ? '/' : '/' . $slug . '/';
    $cls = $slug === $active ? ' class="on"' : '';
    $out .= '<a href="' . $href . '"' . $cls . '>' . $label . '</a>';
  }
  return $out . '</div></details>';
}

function site_page($active, $title, $bodyHtml) {
  $nav = site_nav($active);
  $t = theme_vars(site_theme());
  $vars = '';
  foreach ($t['vars'] as $k => $v) { $vars .= "$k: $v; "; }
  $scheme = $t['scheme'];
  $bg = $t['vars']['--bg'];
  echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="$bg">
<link rel="icon" type="image/png" href="/favicon-32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<title>$title &middot; seancheren.com</title>
<style>
  :root { {$vars}color-scheme: $scheme; }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    line-height: 1.6; -webkit-font-smoothing: antialiased;
    padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
  }
  .wrap { max-width: 640px; margin: 0 auto; padding: 1.5rem 1.25rem 4rem; }
  /* The site's mark: a cursive SC, centred above the nav. Apple's script faces with a
     generic-cursive fallback elsewhere; the favicon bakes the same mark into pixels. */
  .sitelogo {
    display: block; text-align: center; font-family: "Savoye LET", "Snell Roundhand", cursive;
    font-size: 3.4rem; line-height: 1; color: var(--accent); text-decoration: none;
    margin: 0.2rem 0 0.5rem;
  }
  .sitelogo:hover { opacity: 0.8; }
  .sitenav {
    display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center;
    padding-bottom: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--line-soft);
  }
  .sitenav a {
    text-decoration: none; color: var(--text-dim); border: 1px solid var(--line); background: var(--surface);
    border-radius: 999px; padding: 0.3rem 0.9rem; font-size: 0.95rem;
  }
  .sitenav a:hover { background: var(--surface-2); color: var(--text); }
  .sitenav a.on { background: var(--accent); border-color: var(--accent); color: var(--accent-ink); font-weight: 700; }
  /* Phone widths swap the pill row for the dropdown; same rule under either. */
  .sitenav-dd { display: none; }
  @media (max-width: 480px) {
    .sitenav { display: none; }
    .sitenav-dd {
      display: flex; justify-content: center; position: relative;
      padding-bottom: 0.75rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--line-soft);
    }
    .sitenav-dd summary {
      list-style: none; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;
      background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 0.95rem;
      border: 1px solid var(--accent); border-radius: 999px; padding: 0.3rem 1rem;
    }
    .sitenav-dd summary::-webkit-details-marker { display: none; }
    .sitenav-dd .caret { font-size: 0.7em; line-height: 1; }
    .sitenav-dd .menu {
      position: absolute; top: 100%; left: 50%; transform: translateX(-50%); z-index: 10;
      min-width: 11rem; background: var(--surface); border: 1px solid var(--line);
      border-radius: 12px; padding: 0.35rem; display: flex; flex-direction: column;
    }
    .sitenav-dd .menu a {
      text-decoration: none; color: var(--text-dim); border-radius: 8px;
      padding: 0.5rem 0.9rem; font-size: 0.95rem; text-align: center;
    }
    .sitenav-dd .menu a:hover { background: var(--surface-2); color: var(--text); }
    .sitenav-dd .menu a.on { color: var(--accent); font-weight: 700; }
  }
  /* One luminance ramp rather than three unrelated colours: the page title is the
     brightest thing on the page, section headings carry the accent, and subheadings sit
     a step dimmer — so the hierarchy reads without any of the three competing with the
     accent of a link in body text. */
  h1 {
    font-size: 1.7rem; margin: 0.5rem 0 1rem; color: var(--text);
    letter-spacing: -0.015em; font-weight: 700;
  }
  h2 {
    font-size: 1.25rem; margin: 2rem 0 0.5rem; color: var(--accent);
    letter-spacing: -0.01em;
  }
  h3 { font-size: 1.05rem; margin: 1.5rem 0 0.4rem; color: var(--text-dim); font-weight: 600; }
  h4 { font-size: 0.95rem; margin: 1.1rem 0 0.3rem; color: var(--muted); font-weight: 600; }
  p { margin: 0.75rem 0; color: var(--text-dim); }
  /* Underlined, so a link inside a section never reads as another heading. */
  a { color: var(--accent); text-underline-offset: 2px; }
  a:hover { opacity: 0.8; }
  ul { margin: 0.5rem 0; padding-left: 1.25rem; }
  /* Gap below only, never above: a column break truncates the margin over whichever item
     starts a new column, so a top margin lands on the first column's first item and on
     nothing else — which sat the two columns of .lists-col a few pixels out of step. On an
     ordinary list this changes nothing, since that margin only ever collapsed into ul's. */
  li { margin: 0 0 0.2rem; }
  .sig { margin-top: 1.5rem; color: var(--muted); }
  .sig .date { font-size: 0.85rem; }
  /* Two columns flow independently, so their rows only line up while every item is one
     line tall. .wrap caps at 640px, so above that the columns are a fixed 284px and the
     items fit; below it they narrow and the long titles start wrapping, stepping one
     column out of step with the other — hence one column from there down, not from 480. */
  .lists-col ul { columns: 2; column-gap: 2rem; }
  @media (max-width: 640px) { .lists-col ul { columns: 1; } }
</style>
</head>
<body>
<div class="wrap">
<a class="sitelogo" href="/" aria-label="Home">SC</a>
$nav
$bodyHtml
</div>
</body>
</html>
HTML;
}
