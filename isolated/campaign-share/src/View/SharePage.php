<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\View;

use Qenda\CampaignShare\Copy;

final class SharePage
{
    /** @param array{invite_valid: bool, invite: string, period_closed: bool, csrf_token: string, dialog_open: bool} $view */
    public function render(array $view): string
    {
        $valid = $view['invite_valid'];
        $invite = $valid ? $this->e($view['invite']) : '';
        $csrf = $this->e($view['csrf_token']);
        $open = $view['dialog_open'] ? ' open' : '';
        $banner = $view['period_closed']
            ? '<p role="status">' . $this->e(Copy::CLOSURE_BANNER) . '</p>'
            : '';
        $invalid = $valid ? '' : '<p role="alert">' . $this->e(Copy::INVALID_LINK) . '</p>';
        $dialog = '';
        if ($valid) {
            $help = $this->e(Copy::DIALOG_HELP);
            $phone = $this->e(Copy::PHONE_LABEL);
            $proof = $this->e(Copy::PROOF_LABEL);
            $proofHelp = $this->e(Copy::PROOF_HELP);
            $confirm = $this->e(Copy::CONFIRM);
            $cancel = $this->e(Copy::CANCEL);
            $yourKey = $this->e(Copy::YOUR_KEY);
            $bound = $this->e(Copy::BOUND_PHONE);
            $go = $this->e(Copy::GO_USE);
            $dialog = "<dialog id=\"claim-dialog\"{$open}>"
                . "<p>{$help}</p>"
                . '<form id="claim-form" method="post" action="/campaign/share.html" autocomplete="off">'
                . "<label>{$phone} <input id=\"phone\" name=\"phone\" autocomplete=\"tel\" inputmode=\"numeric\"></label>"
                . "<label>{$proof} <input id=\"proof\" name=\"proof\" autocomplete=\"off\"></label>"
                . "<p>{$proofHelp}</p>"
                . "<button type=\"submit\">{$confirm}</button>"
                . "<button type=\"button\" id=\"cancel-claim\">{$cancel}</button>"
                . '</form>'
                . '<p id="claim-error" role="alert"></p>'
                . '<div id="claim-result" hidden>'
                . '<h2 id="result-title"></h2><p id="result-status"></p>'
                . "<p>{$yourKey}</p><p id=\"result-key\"></p>"
                . "<p>{$bound}</p><p id=\"result-phone\"></p><p id=\"result-hint\"></p>"
                . "<button type=\"button\" id=\"go-use\">{$go}</button>"
                . '</div></dialog>';
        }
        $button = $valid ? '<button type="button" id="open-claim">' . $this->e(Copy::OPEN_CLAIM) . '</button>' : '';
        $brand = $this->e(Copy::BRAND);
        $title = $this->e(Copy::TITLE);
        $intro = $this->e(Copy::INTRO);
        $note = $this->e(Copy::VALUE_NOTE);
        $v1 = $this->e(Copy::VALUE_1);
        $v2 = $this->e(Copy::VALUE_2);
        $v3 = $this->e(Copy::VALUE_3);
        $shared = $this->e(Copy::SHARED_BY);
        $noscript = $this->e(Copy::NOSCRIPT);
        $validFlag = $valid ? '1' : '0';
        $closedFlag = $view['period_closed'] ? '1' : '0';

        return '<!DOCTYPE html><html lang="zh-CN"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<meta name="csrf-token" content="' . $csrf . '">'
            . '<title>' . $brand . '</title>'
            . '<link rel="stylesheet" href="/campaign/share.css">'
            . '</head><body><header>' . $brand . '</header>'
            . '<main id="share-root" data-enabled="1" data-invite-valid="' . $validFlag . '" data-period-closed="' . $closedFlag . '" data-invite="' . $invite . '">'
            . '<h1>' . $title . '</h1><p>' . $intro . '</p><p>' . $note . '</p><ul><li>' . $v1 . '</li><li>' . $v2 . '</li><li>' . $v3 . '</li></ul>'
            . $banner . $invalid . $button . '<p>' . $shared . '</p>' . $dialog
            . '</main><noscript><p>' . $noscript . '</p></noscript>'
            . '<script src="/campaign/share.js"></script></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
