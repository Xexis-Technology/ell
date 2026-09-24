<?php
/** Branded email wrapper. Variables: $body (HTML), $orgName. */
/** @var string $body */ /** @var string $orgName */
?>
<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;background:#0A0A0C;color:#F5F5F3;padding:24px;border-radius:8px;">
  <h2 style="color:#F3D4A6;font-family:Georgia,serif;">Exotic Lane Limo</h2>
  <?= $body ?>
  <hr style="border-color:#333;">
  <p style="font-size:12px;color:#AB8868;"><?= e($orgName) ?></p>
</div>
