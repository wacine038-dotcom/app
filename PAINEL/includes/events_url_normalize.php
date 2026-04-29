<?php
/**
 * Base URL for the app sport tab: it appends "campeonatos" and "jogos" to this value.
 */
function unipro_normalize_events_url_string($s): string
{
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    return rtrim($s, '/') . '/';
}
