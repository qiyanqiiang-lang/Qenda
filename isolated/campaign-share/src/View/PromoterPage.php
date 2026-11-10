<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\View;

use Qenda\CampaignShare\Copy;

final class PromoterPage
{
    /**
     * @param array{
     *   authorized: bool,
     *   message: string,
     *   items: list<array<string, string|null>>,
     *   detail: ?array<string, string|null>,
     *   csrf_token: string
     * } $view
     */
    public function render(array $view): string
    {
        if (!$view['authorized']) {
            return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="referrer" content="no-referrer"></head><body><p>'
                . $this->e(Copy::VERIFY_FAIL) . '</p></body></html>';
        }
        $rows = '';
        foreach ($view['items'] as $item) {
            $rows .= '<tr>'
                . '<td>' . $this->e((string) $item['visitor_label']) . '</td>'
                . '<td>' . $this->e((string) $item['key_public_id']) . '</td>'
                . '<td>' . $this->e((string) $item['status_label']) . '</td>'
                . '<td>' . $this->e((string) $item['claimed_at']) . '</td>'
                . '<td>' . $this->e((string) ($item['used_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        $detail = '';
        if ($view['detail'] !== null) {
            $item = $view['detail'];
            $detail = '<section><h2>详情</h2><dl>'
                . '<dt>用户序号</dt><dd>' . $this->e((string) $item['visitor_label']) . '</dd>'
                . '<dt>密钥标识</dt><dd>' . $this->e((string) $item['key_public_id']) . '</dd>'
                . '<dt>状态</dt><dd>' . $this->e((string) $item['status_label']) . '</dd>'
                . '<dt>领取时间</dt><dd>' . $this->e((string) $item['claimed_at']) . '</dd>'
                . '<dt>使用时间</dt><dd>' . $this->e((string) ($item['used_at'] ?? '')) . '</dd>'
                . '</dl></section>';
        }
        $message = $view['message'] === '' ? '' : '<p role="alert">' . $this->e($view['message']) . '</p>';
        $csrf = $this->e($view['csrf_token']);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <meta name="csrf-token" content="{$csrf}">
  <title>推广领取记录</title>
  <link rel="stylesheet" href="/campaign/share.css">
</head>
<body>
  <main>
    <h1>推广领取记录</h1>
    <p>这里只显示序号、密钥标识、状态和时间。</p>
    {$message}
    <form method="post" action="/campaign/promoter.html" autocomplete="off">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <label>搜索 <input name="q" autocomplete="off"></label>
      <button type="submit">搜索</button>
    </form>
    <table>
      <thead><tr><th>用户序号</th><th>密钥标识</th><th>状态</th><th>领取时间</th><th>使用时间</th></tr></thead>
      <tbody>{$rows}</tbody>
    </table>
    {$detail}
  </main>
</body>
</html>
HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
