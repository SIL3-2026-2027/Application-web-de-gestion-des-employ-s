<?php

namespace App\Notifications;

/** Notification dont le texte est fourni à la construction (modules documents, etc.). */
class SimpleNotification extends AppNotification
{
    public function __construct(
        public string $categoryKey,
        public string $titleText,
        public string $messageText,
        public string $link,
    ) {}

    public function category(): string
    {
        return $this->categoryKey;
    }

    public function title(): string
    {
        return $this->titleText;
    }

    public function message(): string
    {
        return $this->messageText;
    }

    public function url(): string
    {
        return $this->link;
    }
}
