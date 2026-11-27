<?php
declare(strict_types=1);

namespace App;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/** An expired form (419) returns staff to the page they came from with a message, not a bare error page. */
final class FormExpired implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'back'];
    }

    public function back(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $request = $event->getRequest();
        if (!$error instanceof HttpExceptionInterface || $error->getStatusCode() !== 419 || !$request->hasSession()) {
            return;
        }
        $request->getSession()->getFlashBag()->add('error', 'This form expired. Check the details and submit again.');
        $referer = (string)$request->headers->get('referer');
        $sameSite = str_starts_with($referer, $request->getSchemeAndHttpHost().'/');
        $event->setResponse(new RedirectResponse($sameSite ? $referer : $request->getBasePath().'/'));
    }
}
