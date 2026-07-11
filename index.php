<?php
/**
 * Redirect /api root hits into /public for http://localhost/api/...
 */
header('Location: /api/public/');
exit;
