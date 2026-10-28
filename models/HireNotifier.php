<?php

declare(strict_types=1);

namespace Osmium\Services\OsmiumHire\Models;

use Osmium\Core\Library\MailerFactory;
use Osmium\Core\Library\ServiceHooks;
use Osmium\Core\Library\ServiceLoader;
use Osmium\Core\Models\ServiceModel;

/**
 * Tells the customer (and the business) what happened to an hire, and announces it to other services with a hook
 * (hire.requested, .confirmed, .changed, .declined, .cancelled). A mail that fails to send is logged and never
 * stops the hire; a service that fails on a hook is logged by core and skipped.
 */
class HireNotifier
{
    public function __construct(private object $osmium)
    {
    }

    public function requested(array $hire): void
    {
        $siteName = $this->siteName();
        $this->send(to: $hire['customer_email'], mail: HireMail::received($hire, $siteName));

        $adminUrl = $this->osmium->config->site->FQDN . '/' . $this->osmium->config->site->adminPath . '/hire' . $this->slash();
        $team = HireMail::requestForTeam(hire: $hire, siteName: $siteName, adminUrl: $adminUrl);
        foreach (HireConfig::get()['notifyEmails'] as $email) {
            $this->send(to: $email, mail: $team);
        }

        $this->announce(event: 'hire.requested', hire: $hire);
    }

    public function confirmed(array $hire): void
    {
        $this->send(to: $hire['customer_email'], mail: HireMail::confirmed($hire, $this->siteName(), $this->icsUrl($hire), $this->googleUrl($hire), (string) ($hire['pickup_details'] ?? '')));
        $this->announce(event: 'hire.confirmed', hire: $hire);
    }

    public function changed(array $hire): void
    {
        $this->send(to: $hire['customer_email'], mail: HireMail::changed($hire, $this->siteName(), $this->icsUrl($hire), $this->googleUrl($hire), (string) ($hire['pickup_details'] ?? '')));
        $this->announce(event: 'hire.changed', hire: $hire);
    }

    public function declined(array $hire, string $message = ''): void
    {
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        $this->send(to: $hire['customer_email'], mail: HireMail::declined(hire: $hire, siteName: $this->siteName(), message: $message, phone: $phone));
        $this->announce(event: 'hire.declined', hire: $hire);
    }

    public function cancelled(array $hire, string $message = ''): void
    {
        $phone = \trim((string) ($this->osmium->config->company->phone ?? ''));
        $this->send(to: $hire['customer_email'], mail: HireMail::cancelled(hire: $hire, siteName: $this->siteName(), message: $message, phone: $phone));
        $this->announce(event: 'hire.cancelled', hire: $hire);
    }

    public function icsUrl(array $hire): string
    {
        return $this->osmium->config->site->FQDN . '/api/hire-calendar' . $this->slash() . '?ref=' . \rawurlencode((string) $hire['reference']);
    }

    private function googleUrl(array $hire): string
    {
        $siteName = $this->siteName();

        return HireIcs::googleLink(
            hire: $hire,
            summary: HireMail::eventTitle($hire, $siteName),
            description: 'Hire reference ' . $hire['reference'],
            zone: new \DateTimeZone(\date_default_timezone_get()),
        );
    }

    /** The site's own habit for the end of a URL: the web server redirects the other form */
    private function slash(): string
    {
        return ($this->osmium->config->site->urlTrailingSlash ?? false) ? '/' : '';
    }

    private function siteName(): string
    {
        return (string) $this->osmium->config->site->name;
    }

    /**
     * @param array{subject: string, html: string} $mail
     */
    private function send(string $to, array $mail): void
    {
        $noAddress = \filter_var($to, FILTER_VALIDATE_EMAIL) === false; // A telephone customer may have given none
        if ($noAddress) return;

        try {
            $result = MailerFactory::make($this->osmium->config)->send(
                recipients: [['email' => $to]],
                subject: $mail['subject'],
                htmlBody: $mail['html'],
            );
            $failed = empty($result['success']);
            if ($failed) \error_log('Hires: mail to ' . $to . ' failed: ' . ($result['error'] ?? 'unknown error'));
        } catch (\Throwable $e) {
            \error_log('Hires: mail to ' . $to . ' failed: ' . $e->getMessage());
        }
    }

    private function announce(string $event, array $hire): void
    {
        $hooks = new ServiceHooks(new ServiceLoader(new ServiceModel($this->osmium->dataSource)));
        $hooks->dispatch($event, ['hire' => $hire, 'hireId' => (int) $hire['id'], 'dataSource' => $this->osmium->dataSource, 'osmium' => $this->osmium]);
    }
}
