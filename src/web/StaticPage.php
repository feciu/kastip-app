<?php
declare(strict_types=1);

namespace KasTip\Web;

use KasTip\App;

/**
 * Tiny base for server-rendered static pages (Terms, Privacy, etc.).
 * Shared dark-theme shell to match landing/dashboard look.
 */
final class StaticPage
{
    public static function render(string $title, string $heroSubtitle, string $bodyHtml, string $lastUpdated): void
    {
        $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $subEsc   = htmlspecialchars($heroSubtitle, ENT_QUOTES, 'UTF-8');
        $updEsc   = htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $titleEsc ?> — KasTip</title>
<meta name="robots" content="index,follow">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{min-height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;line-height:1.65;
  }
  a{color:#49e9c9;text-decoration:none}
  a:hover{text-decoration:underline}
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:1.25rem 1.5rem;max-width:1100px;margin:0 auto;
  }
  .logo{
    font-size:1.5rem;font-weight:800;letter-spacing:-0.03em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  main{max-width:720px;margin:2rem auto 4rem;padding:0 1.5rem}
  h1{font-size:2rem;font-weight:700;letter-spacing:-0.02em;margin-bottom:.5rem}
  .lead{color:#a8b1c2;font-size:1.05rem;margin-bottom:.5rem}
  .updated{color:#5a6378;font-size:.85rem;margin-bottom:2rem}
  h2{font-size:1.2rem;font-weight:600;margin:2rem 0 .75rem;color:#e8eaed}
  h3{font-size:1rem;font-weight:600;margin:1.5rem 0 .5rem;color:#a8b1c2}
  p, ul, ol {margin-bottom:1rem;color:#cdd3df}
  ul, ol {padding-left:1.4rem}
  li {margin-bottom:.4rem}
  code {
    font-family:ui-monospace,SF Mono,Monaco,monospace;
    font-size:.88em;background:#161927;padding:.1rem .4rem;border-radius:4px;
    color:#a8b1c2;
  }
  strong {color:#e8eaed}
  footer{
    text-align:center;padding:2rem 1.5rem 3rem;color:#5a6378;font-size:.85rem;
    border-top:1px solid #1f2335;margin-top:3rem;
  }
  footer a{color:#a8b1c2}
  footer a:hover{color:#49e9c9}
</style>
</head>
<body>
<header>
  <a href="/" class="logo">KasTip</a>
  <a href="/" style="color:#a8b1c2;font-size:.9rem">← Home</a>
</header>

<main>
  <h1><?= $titleEsc ?></h1>
  <p class="lead"><?= $subEsc ?></p>
  <p class="updated">Last updated: <?= $updEsc ?></p>

  <?= $bodyHtml ?>
</main>

<footer>
  <p>
    <a href="/terms">Terms</a> ·
    <a href="/privacy">Privacy</a> ·
    <a href="/">Home</a> ·
    <a href="https://github.com/feciu/kastip-app" target="_blank" rel="noopener">Source</a>
  </p>
</footer>
</body>
</html>
        <?php
        exit;
    }
}
