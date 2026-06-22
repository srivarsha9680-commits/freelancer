<?php
function loadTemplate(string $name): array {
    $path = __DIR__ . '/../assets/templates/' . $name . '.php';
    if (!file_exists($path)) return [];
    return include $path;
}

function formatTemplateValue($value): string {
    if (is_array($value)) {
        $html = '<ul>';
        foreach ($value as $item) {
            $html .= '<li>' . htmlspecialchars((string)$item) . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    return htmlspecialchars((string)$value);
}

function processTemplateString(string $template, array $data): string {
    $output = $template;
    foreach ($data as $k => $v) {
        $output = str_replace('{{' . $k . '}}', formatTemplateValue($v), $output);
    }
    return $output;
}

function renderTemplate(array $tpl, array $data): string {
    $html = '';
    if (isset($tpl['sections']['overview'])) {
        $html .= '<h3>Overview</h3><p>' . processTemplateString($tpl['sections']['overview'], $data) . '</p>';
    }
    if (!empty($tpl['sections']['deliverables']) && is_array($tpl['sections']['deliverables'])) {
        $html .= '<h3>Deliverables</h3><ul>';
        foreach ($tpl['sections']['deliverables'] as $d) {
            $html .= '<li>' . processTemplateString($d, $data) . '</li>';
        }
        $html .= '</ul>';
    }
    if (isset($tpl['sections']['payment'])) {
        $html .= '<h3>Payment</h3><p>' . processTemplateString($tpl['sections']['payment'], $data) . '</p>';
    }
    return $html;
}
