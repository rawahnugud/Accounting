<?php
declare(strict_types=1);

namespace App;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class Language extends AbstractExtension implements EventSubscriberInterface
{
    private const COOKIE = 'mt_accounting_language';
    private ?array $arabic = null;

    public function __construct(private RequestStack $requests) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['choose', 12], KernelEvents::RESPONSE => 'remember'];
    }

    public function choose(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $choice = $request->query->all()['lang'] ?? null;
        $locale = in_array($choice, ['en','ar'], true) ? $choice : $request->cookies->get(self::COOKIE);
        $request->setLocale($locale === 'ar' ? 'ar' : 'en');
    }

    public function remember(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->query->all()['lang'] ?? null, ['en','ar'], true)) {
            return;
        }
        $event->getResponse()->headers->setCookie(Cookie::create(self::COOKIE, $request->getLocale(), strtotime('+1 year'),
            '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    public function getFilters(): array
    {
        return [new TwigFilter('ui', [$this,'label'])];
    }

    public function label(mixed $value): string
    {
        $text = (string)$value;
        if ($this->requests->getCurrentRequest()?->getLocale() !== 'ar' || $text === '') {
            return $text;
        }
        $this->arabic ??= json_decode(file_get_contents(dirname(__DIR__).'/resources/i18n/ar.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = trim($text);
        $arabic = $this->arabic[$key] ?? $this->arabic[ucfirst($key)] ?? null;
        if ($arabic !== null) {
            return str_replace($key,$arabic,$text);
        }
        if (preg_match('/^Set a rate for ([A-Z]{3}) on this date\.$/', $text, $m)) {
            return 'حدّد سعر صرف '.$m[1].' في هذا التاريخ.';
        }
        if (preg_match('/^Transfer #(\d+) recorded\.$/', $text, $m)) {
            return 'تم تسجيل التحويل رقم '.$m[1].'.';
        }
        if (preg_match('/^Use at most (\d) decimal places\.$/', $text, $m)) {
            return 'استخدم '.$m[1].' منازل عشرية كحد أقصى.';
        }
        if (preg_match('/^Check (\w+)\.$/', $text, $m)) {
            return 'تحقق من الحقل: '.($this->arabic[ucfirst($m[1])] ?? $m[1]).'.';
        }
        return $text;
    }
}
