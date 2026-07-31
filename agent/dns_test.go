package main

import (
	"testing"
	"time"
)

// Verbatim from /var/log/vpnforge/dns-wg0.log on a running server (dnsmasq
// 2.90, --log-queries=extra), with the client rewritten to a tunnel peer.
const (
	simpleLookup = `Jul 31 21:09:20 dnsmasq[11905]: 1 10.60.0.2/50780 query[A] example.com from 10.60.0.2
Jul 31 21:09:20 dnsmasq[11905]: 1 10.60.0.2/50780 forwarded example.com to 1.1.1.1
Jul 31 21:09:20 dnsmasq[11905]: 1 10.60.0.2/50780 forwarded example.com to 1.0.0.1
Jul 31 21:09:20 dnsmasq[11905]: 1 10.60.0.2/50780 reply example.com is 172.66.147.243
Jul 31 21:09:20 dnsmasq[11905]: 1 10.60.0.2/50780 reply example.com is 104.20.23.154`

	cnameLookup = `Jul 31 21:09:20 dnsmasq[11905]: 2 10.60.0.2/50428 query[A] www.wikipedia.org from 10.60.0.2
Jul 31 21:09:20 dnsmasq[11905]: 2 10.60.0.2/50428 forwarded www.wikipedia.org to 1.0.0.1
Jul 31 21:09:20 dnsmasq[11905]: 2 10.60.0.2/50428 reply www.wikipedia.org is <CNAME>
Jul 31 21:09:20 dnsmasq[11905]: 2 10.60.0.2/50428 reply dyna.wikimedia.org is 208.80.154.224`

	cachedLookup = `Jul 31 21:10:02 dnsmasq[11905]: 3 10.60.0.2/51001 query[AAAA] example.com from 10.60.0.2
Jul 31 21:10:02 dnsmasq[11905]: 3 10.60.0.2/51001 cached example.com is 2606:2800:21f:cb07::1`
)

func feed(t *testing.T, users map[string]UserInfo, blocks ...string) []TrafficLogRow {
	t.Helper()

	var rows []TrafficLogRow

	correlator := newDNSCorrelator(7, func(row TrafficLogRow) {
		rows = append(rows, row)
	}, func() map[string]UserInfo { return users })

	now := time.Now()

	for _, block := range blocks {
		for _, line := range splitLines(block) {
			correlator.handleLine(line, now)
		}
	}

	correlator.flushAll()

	return rows
}

func splitLines(block string) []string {
	var lines []string
	start := 0

	for i := 0; i < len(block); i++ {
		if block[i] == '\n' {
			lines = append(lines, block[start:i])
			start = i + 1
		}
	}

	return append(lines, block[start:])
}

func loggedPeer() map[string]UserInfo {
	return map[string]UserInfo{
		"10.60.0.2": {ID: 42, TunnelIP: "10.60.0.2", LoggingEffective: true},
	}
}

func TestCorrelatorRecordsAllThreeLegsOfALookup(t *testing.T) {
	rows := feed(t, loggedPeer(), simpleLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row for one lookup, got %d", len(rows))
	}

	row := rows[0]

	if row.Host != "example.com" {
		t.Errorf("host = %q, want example.com", row.Host)
	}

	// Client -> server.
	if row.SourceIP != "10.60.0.2" {
		t.Errorf("source ip = %q, want 10.60.0.2", row.SourceIP)
	}

	if row.Detail["query_type"] != "A" {
		t.Errorf("query_type = %v, want A", row.Detail["query_type"])
	}

	// Server -> upstream.
	forwarded, _ := row.Detail["forwarded_to"].([]string)
	if len(forwarded) != 2 || forwarded[0] != "1.1.1.1" || forwarded[1] != "1.0.0.1" {
		t.Errorf("forwarded_to = %v, want both upstreams", row.Detail["forwarded_to"])
	}

	// The answer.
	answers, _ := row.Detail["answers"].([]string)
	if len(answers) != 2 || answers[0] != "172.66.147.243" {
		t.Errorf("answers = %v, want both A records", row.Detail["answers"])
	}

	if row.Detail["cached"] != false {
		t.Errorf("cached = %v, want false", row.Detail["cached"])
	}
}

func TestCorrelatorFollowsACnameChain(t *testing.T) {
	rows := feed(t, loggedPeer(), cnameLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row, got %d", len(rows))
	}

	row := rows[0]

	// Recorded under the name the client actually asked for, not the alias.
	if row.Host != "www.wikipedia.org" {
		t.Errorf("host = %q, want www.wikipedia.org", row.Host)
	}

	chain, _ := row.Detail["cname_chain"].([]string)
	if len(chain) == 0 {
		t.Fatalf("cname_chain missing: %v", row.Detail)
	}

	found := false
	for _, name := range chain {
		if name == "dyna.wikimedia.org" {
			found = true
		}
	}

	if !found {
		t.Errorf("cname_chain = %v, want it to include dyna.wikimedia.org", chain)
	}

	answers, _ := row.Detail["answers"].([]string)
	if len(answers) != 1 || answers[0] != "208.80.154.224" {
		t.Errorf("answers = %v, want the resolved address only", row.Detail["answers"])
	}

	// "<CNAME>" is a marker, not an address -- it must not leak into answers.
	for _, answer := range answers {
		if answer == "<CNAME>" {
			t.Errorf("answers contains the <CNAME> marker: %v", answers)
		}
	}
}

func TestCorrelatorMarksCacheHitsAndRecordsNoUpstream(t *testing.T) {
	rows := feed(t, loggedPeer(), cachedLookup)

	if len(rows) != 1 {
		t.Fatalf("expected one row, got %d", len(rows))
	}

	if rows[0].Detail["cached"] != true {
		t.Errorf("cached = %v, want true", rows[0].Detail["cached"])
	}

	if _, ok := rows[0].Detail["forwarded_to"]; ok {
		t.Errorf("a cache hit must not claim an upstream: %v", rows[0].Detail)
	}
}

func TestCorrelatorKeepsConcurrentLookupsApart(t *testing.T) {
	rows := feed(t, loggedPeer(), simpleLookup, cnameLookup, cachedLookup)

	if len(rows) != 3 {
		t.Fatalf("expected three rows, got %d", len(rows))
	}

	hosts := map[string]bool{}
	for _, row := range rows {
		hosts[row.Host] = true
	}

	for _, want := range []string{"example.com", "www.wikipedia.org"} {
		if !hosts[want] {
			t.Errorf("missing a row for %s", want)
		}
	}
}

func TestCorrelatorSkipsPeersWithLoggingOff(t *testing.T) {
	users := map[string]UserInfo{
		"10.60.0.2": {ID: 42, TunnelIP: "10.60.0.2", LoggingEffective: false},
	}

	if rows := feed(t, users, simpleLookup); len(rows) != 0 {
		t.Errorf("expected nothing recorded, got %d rows", len(rows))
	}
}

func TestCorrelatorIgnoresUnknownPeers(t *testing.T) {
	if rows := feed(t, map[string]UserInfo{}, simpleLookup); len(rows) != 0 {
		t.Errorf("expected nothing recorded for an unknown peer, got %d rows", len(rows))
	}
}

func TestCorrelatorFlushesWhenAnIdIsReused(t *testing.T) {
	users := loggedPeer()

	var rows []TrafficLogRow
	correlator := newDNSCorrelator(7, func(row TrafficLogRow) {
		rows = append(rows, row)
	}, func() map[string]UserInfo { return users })

	now := time.Now()

	for _, line := range splitLines(simpleLookup) {
		correlator.handleLine(line, now)
	}

	// dnsmasq recycles ids; a fresh query under id 1 means the previous one
	// is finished and must not be silently dropped.
	correlator.handleLine("Jul 31 21:11:00 dnsmasq[11905]: 1 10.60.0.2/50999 query[A] anthropic.com from 10.60.0.2", now)

	if len(rows) != 1 || rows[0].Host != "example.com" {
		t.Fatalf("reusing an id should have flushed the first lookup, got %v", rows)
	}
}

func TestCorrelatorFlushesAfterTheTransactionGoesQuiet(t *testing.T) {
	users := loggedPeer()

	var rows []TrafficLogRow
	correlator := newDNSCorrelator(7, func(row TrafficLogRow) {
		rows = append(rows, row)
	}, func() map[string]UserInfo { return users })

	start := time.Now()

	for _, line := range splitLines(simpleLookup) {
		correlator.handleLine(line, start)
	}

	correlator.sweep(start.Add(time.Second))
	if len(rows) != 0 {
		t.Fatalf("swept too early: %v", rows)
	}

	correlator.sweep(start.Add(dnsTransactionTTL + time.Second))
	if len(rows) != 1 {
		t.Fatalf("expected the lookup to be written out once quiet, got %d rows", len(rows))
	}
}
