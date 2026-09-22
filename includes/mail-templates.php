<?php
/**
 * Shared HTML email layout. Email clients need inline CSS (no external
 * stylesheets, many strip <style> blocks too), so everything here is
 * inlined directly on each element.
 */

function hef_email_wrap(string $heading, string $innerHtml, string $preheader = ''): string
{
    return '
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
    <body style="margin:0; padding:0; background:#f5f7f6; font-family:Arial,Helvetica,sans-serif;">
        <span style="display:none; max-height:0; overflow:hidden;">' . htmlspecialchars($preheader) . '</span>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7f6; padding:24px 0;">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border-radius:14px; overflow:hidden;">
                        <tr>
                            <td style="background:linear-gradient(135deg,#2f7d4f,#1d4a30); background-color:#2f7d4f; padding:24px 28px;">
                                <span style="color:#ffffff; font-size:22px; font-weight:bold; letter-spacing:0.5px;">HEFarm</span>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:28px;">
                                <h2 style="margin:0 0 16px; color:#1d4a30; font-size:20px;">' . htmlspecialchars($heading) . '</h2>
                                ' . $innerHtml . '
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:18px 28px; background:#f5f7f6; color:#888; font-size:12px;">
                                This is an automated message from HEFarm — Farm Management.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

function hef_email_button(string $url, string $label): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;">
        <tr><td style="background:#2f7d4f; border-radius:8px;">
            <a href="' . htmlspecialchars($url) . '" style="display:inline-block; padding:12px 24px; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px;">' . htmlspecialchars($label) . '</a>
        </td></tr>
    </table>';
}

/** A simple two-column "label: value" details table, styled. */
function hef_email_details_table(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0; border:1px solid #eee; border-radius:8px; overflow:hidden;">';
    $i = 0;
    foreach ($rows as $label => $value) {
        $bg = $i % 2 === 0 ? '#fafafa' : '#ffffff';
        $html .= '<tr style="background:' . $bg . ';">
            <td style="padding:10px 14px; color:#666; font-size:13px; width:45%; border-bottom:1px solid #f0f0f0;">' . htmlspecialchars($label) . '</td>
            <td style="padding:10px 14px; color:#222; font-size:13px; font-weight:600; border-bottom:1px solid #f0f0f0;">' . htmlspecialchars((string) $value) . '</td>
        </tr>';
        $i++;
    }
    $html .= '</table>';
    return $html;
}

function hef_email_alert_badge(string $text, string $color = '#c0392b'): string
{
    return '<span style="display:inline-block; padding:4px 10px; background:' . $color . '1A; color:' . $color . '; border-radius:6px; font-size:12px; font-weight:bold;">' . htmlspecialchars($text) . '</span>';
}
