<?php

namespace Spatie\MailPreview;

use Carbon\Carbon;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Transport\Transport;
use Illuminate\Support\Str;
use Spatie\MailPreview\Events\MailStoredEvent;
use Swift_Mime_SimpleMessage;
use Symfony\Component\Finder\SplFileInfo;

class PreviewMailTransport extends Transport
{
    protected Filesystem $files;

    protected string $previewPath;

    protected int $maximumLifeTimeInSeconds;

    public function __construct(Filesystem $files, string $previewPath, int $maximumLifeTimeInSeconds = 60)
    {
        $this->files = $files;

        $this->previewPath = $previewPath;

        $this->maximumLifeTimeInSeconds = $maximumLifeTimeInSeconds;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $this
            ->ensureEmailPreviewDirectoryExists()
            ->cleanOldPreviews();

        $previewPath = $this->getPreviewFilePath($message);

        session()->put('mail_preview_file_name', basename($previewPath));

        $htmlFullPath = "{$previewPath}.html";
        $emlFullPath = "{$previewPath}.html";

        $this->files->put($htmlFullPath, $this->getHtmlPreviewContent($message));
        $this->files->put($emlFullPath, $this->getEmlPreviewContent($message));

        event(new MailStoredEvent($message, $htmlFullPath, $emlFullPath));
    }

    protected function getHtmlPreviewContent(Swift_Mime_SimpleMessage $message): string
    {
        $messageInfo = $this->getMessageInfo($message);

        return $messageInfo . $message->getBody();
    }

    protected function getEmlPreviewContent(Swift_Mime_SimpleMessage $message): string
    {
        return $message->toString();
    }

    protected function getPreviewFilePath(Swift_Mime_SimpleMessage $message): string
    {
        $recipients = array_keys($message->getTo());

        $to = ! empty($recipients)
            ? str_replace(['@', '.'], ['_at_', '_'], $recipients[0]) . '_'
            : '';

        $subject = $message->getSubject();

        return $this->previewPath . '/' . Str::slug($message->getDate()->format('u') . '_' . $to . $subject, '_');
    }

    protected function getMessageInfo(Swift_Mime_SimpleMessage $message): string
    {
        return sprintf(
            "<!--\nFrom:%s, \nto:%s, \nreply-to:%s, \ncc:%s, \nbcc:%s, \nsubject:%s\n-->\n",
            json_encode($message->getFrom()),
            json_encode($message->getTo()),
            json_encode($message->getReplyTo()),
            json_encode($message->getCc()),
            json_encode($message->getBcc()),
            $message->getSubject(),
        );
    }

    protected function ensureEmailPreviewDirectoryExists(): self
    {
        if ($this->files->exists($this->previewPath)) {
            return $this;
        }

        $this->files->makeDirectory($this->previewPath);

        $this->files->put("{$this->previewPath}/.gitignore", '*' . PHP_EOL . '!.gitignore');

        return $this;
    }

    protected function cleanOldPreviews(): self
    {
        collect($this->files->files($this->previewPath))
            ->filter(function (SplFileInfo $path) {
                $fileAgeInSeconds = Carbon::createFromTimestamp($path->getCTime())->diffInSeconds();

                return $fileAgeInSeconds >= $this->maximumLifeTimeInSeconds;
            })
            ->each(fn (SplFileInfo $file) => $this->files->delete(ray()->pass($file->getPathname())));

        return $this;
    }
}
