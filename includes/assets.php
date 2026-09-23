<?php
/** Cache busting: agrega ?v=<fecha de modificación> a cada CSS/JS. */
function asset_url(string $path): string
{
    $full = __DIR__ . '/../' . $path;
    $version = file_exists($full) ? filemtime($full) : time();
    return $path . '?v=' . $version;
}
