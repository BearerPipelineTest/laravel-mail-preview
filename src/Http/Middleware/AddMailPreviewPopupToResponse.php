<?php

namespace Spatie\MailPreview\Http\Middleware;

use Closure;
use Illuminate\Http\Response;

class AddMailPreviewPopupToResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if (! $this->shouldAttachPreviewLinkToResponse($request, $response)) {
            return $request;
        }

        $this->attachPreviewLink(
            $response,
            $request->session()->get('mail_preview_path')
        );

        $request->session()->forget('mail_preview_path');

        return $response;
    }

    protected function shouldAttachPreviewLinkToResponse($request, $response): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        if (! $response instanceof Response) {
            return false;
        }

        if (! $request->hasSession()) {
            return false;
        }

        if (! $request->session()->get('mail_preview_path')) {
            return false;
        }

        return true;
    }

    protected function attachPreviewLink($response, $previewPath)
    {
        $content = $response->getContent();

        $previewUrl = route('mail.preview', ['storage_path' => $previewPath]);

        $timeoutInSeconds = config('mail-preview.popup_timeout_in_seconds');

        $linkContent = view('mail-preview::previewLinkPopup', )
            ->with(compact('previewUrl', 'timeoutInSeconds'))
            ->render();

        $bodyPosition = strripos($content, '</body>');

        if (false !== $bodyPosition) {
            $content = substr($content, 0, $bodyPosition)
                . $linkContent
                . substr($content, $bodyPosition);
        }

        $response->setContent($content);
    }
}
