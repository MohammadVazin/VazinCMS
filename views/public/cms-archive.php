<?php use VazinCMS\Security; use VazinCMS\SiteCanvasRenderer; ?>
<section class="vz-canvas-public"><h1><?=Security::e($archiveTitle)?></h1><?=SiteCanvasRenderer::archive($archive)?></section>
