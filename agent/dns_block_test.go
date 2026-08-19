package main

import "testing"

// Verbatim answer wording dnsmasq (2.90, --log-queries=extra) writes when it
// answers a name locally instead of forwarding it: address=/x/ yields
// "config <name> is NXDOMAIN", and a 0.0.0.0 addn-hosts blocklist entry yields
// "<hostsfile> <name> is 0.0.0.0".
const (
	configBlockedLookup = `Jul 31 21:12:00 dnsmasq[11905]: 4 10.60.0.2/51200 query[A] ads.example.com from 10.60.0.2
Jul 31 21:12:00 dnsmasq[11905]: 4 10.60.0.2/51200 config ads.example.com is NXDOMAIN`

	hostsBlockedLookup = `Jul 31 21:12:05 dnsmasq[11905]: 5 10.60.0.2/51205 query[A] tracker.example.net from 10.60.0.2
Jul 31 21:12:05 dnsmasq[11905]: 5 10.60.0.2/51205 /etc/vpnforge/blocklists/blocklist.hosts tracker.example.net is 0.0.0.0`
)

func TestCorrelatorFlagsAConfigBlockedLookup(t *testing.T) {
	rows := feed(t, loggedPeer(), configBlockedLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row, got %d", len(rows))
	}

	if !rows[0].Blocked.Valid || !rows[0].Blocked.Bool {
		t.Errorf("Blocked = %+v, want a recorded true", rows[0].Blocked)
	}

	// A blocked name is answered locally -- it is never sent upstream.
	if _, ok := rows[0].Detail["forwarded_to"]; ok {
		t.Errorf("a blocked lookup must not record an upstream: %v", rows[0].Detail)
	}
}

func TestCorrelatorFlagsAHostsBlocklistHit(t *testing.T) {
	rows := feed(t, loggedPeer(), hostsBlockedLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row, got %d", len(rows))
	}

	if !rows[0].Blocked.Valid || !rows[0].Blocked.Bool {
		t.Errorf("Blocked = %+v, want a recorded true", rows[0].Blocked)
	}
}

func TestCorrelatorRecordsAResolvedLookupAsNotBlocked(t *testing.T) {
	rows := feed(t, loggedPeer(), simpleLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row, got %d", len(rows))
	}

	// Valid-but-false, not NULL: a forwarded lookup is known to be allowed.
	if !rows[0].Blocked.Valid || rows[0].Blocked.Bool {
		t.Errorf("Blocked = %+v, want a recorded false", rows[0].Blocked)
	}
}
