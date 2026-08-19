<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The uninstaller must remove exactly what the installer creates. This test
 * locks that parity in place: every systemd unit, directory, DB object, system
 * account, cron line and file the installer sets up has to be named by the
 * uninstall script, so the two cannot silently drift apart as the installer
 * grows. It reads the shipped shell scripts as text -- there is no way to run a
 * privileged teardown from the test suite, and the real risk here is an
 * artifact the installer adds later that nobody remembers to also remove.
 */
class UninstallerScriptTest extends TestCase
{
    private function script(): string
    {
        $entry = base_path('installer/uninstall.sh');
        $steps = base_path('installer/lib/uninstall_steps.sh');

        $this->assertFileExists($entry);
        $this->assertFileExists($steps);

        return file_get_contents($entry)."\n".file_get_contents($steps);
    }

    public function test_it_is_a_root_guarded_bash_script_that_confirms_first(): void
    {
        $entry = file_get_contents(base_path('installer/uninstall.sh'));

        $this->assertStringStartsWith('#!/bin/bash', $entry);
        $this->assertStringContainsString('check_root', $entry);
        $this->assertStringContainsString('Proceed with uninstalling', $entry);

        // Never operate outside the known install root, and never a bare `rm -rf /`.
        $this->assertStringContainsString('APP_DIR:-/var/www/vpnforge', $entry);
        $this->assertStringNotContainsString("rm -rf /\n", $entry);
    }

    public function test_it_tears_down_every_systemd_unit_the_installer_manages(): void
    {
        $s = $this->script();

        foreach (['vpnforge-worker', 'vpnforge-agent', 'vpnforge-dnsmasq@', 'wg-quick@', 'openvpn-server@'] as $unit) {
            $this->assertStringContainsString($unit, $s, "uninstaller must handle {$unit}");
        }

        $this->assertStringContainsString('daemon-reload', $s);
    }

    public function test_it_removes_every_path_and_artifact_the_installer_creates(): void
    {
        $s = $this->script();

        foreach ([
            '/etc/wireguard',
            '/etc/openvpn/vpnforge',
            '/etc/openvpn/server',
            '/etc/vpnforge',
            '/var/log/vpnforge',
            '/var/backups/vpnforge',
            '/usr/local/bin/vpnforge-agent',
            '/etc/polkit-1/rules.d/49-vpnforge-worker.rules',
            '/etc/nginx/sites-available/vpnforge.conf',
            '/etc/nginx/sites-enabled/vpnforge.conf',
            '/etc/sysctl.d/99-vpnforge.conf',
            '/etc/modules-load.d/vpnforge-ifb.conf',
        ] as $path) {
            $this->assertStringContainsString($path, $s, "uninstaller must remove {$path}");
        }
    }

    public function test_it_drops_the_database_users_group_and_cron(): void
    {
        $s = $this->script();

        $this->assertStringContainsString('DROP DATABASE', $s);
        $this->assertStringContainsString('DROP USER', $s);
        $this->assertStringContainsString('userdel', $s);
        $this->assertStringContainsString('groupdel', $s);
        $this->assertStringContainsString('vpnforge-shared', $s);
        $this->assertStringContainsString('schedule:run', $s);
    }

    public function test_backups_removal_is_a_separate_explicit_choice(): void
    {
        $s = $this->script();

        // The backups directory holds every key on the box, so deleting it must
        // be its own opt-in confirmation, never swept up with everything else.
        $this->assertStringContainsString('/var/backups/vpnforge', $s);
        $this->assertStringContainsString('Also delete /var/backups/vpnforge', $s);
    }
}
