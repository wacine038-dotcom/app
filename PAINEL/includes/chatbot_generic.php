<?php
/**
 * Normaliza URL do chatbot genérico (qualquer servidor com endpoint POST + JSON).
 */
function chatbot_normalize_api_url(string $url): string {
    $u = trim($url);
    if ($u === '') {
        return $u;
    }
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'http://' . $u;
    }

    return rtrim($u, '/');
}
