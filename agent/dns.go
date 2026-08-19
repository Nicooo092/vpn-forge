package main

import (
	"bufio"
	"context"
	"fmt"
	"io"
	"log"
	"os"
	"regexp"
	"time"
)

// dnsmasq --log-queries=extra prefixes every line of a lookup with a
// transaction id and the client's address:port, which is what lets the three
// legs of one lookup be stitched back together:
//
//	1 10.0.0.2/50780 query[A] example.com from 10.0.0.2
//	1 10.0.0.2/50780 forwarded example.com to 1.1.1.1
//	1 10.0.0.2/50780 reply example.com is 93.184.216.34
//
// A cache hit has no forwarded leg and says "cached" instead of "reply", and a
// CNAME chain shows up as several reply lines under the same id whose names
// differ from the one originally asked for.
var (
	dnsLinePrefix = regexp.MustCompile(`dnsmasq\[\d+\]:\s+(\d+)\s+(\S+?)/\d+\s+(.*?)\s*$`)
	dnsQueryPart  = regexp.MustCompile(`^query\[([^\]]+)\]\s+(\S+)\s+from\s+(\S+)`)
	dnsForwarded  = regexp.MustCompile(`^forwarded\s+(\S+)\s+to\s+(\S+)`)
	dnsAnswer     = regexp.MustCompile(`^(reply|cached)\s+(\S+)\s+is\s+(.+)$`)
	// dnsmasq answers a blocked name itself instead of forwarding it: a
	// per-service address=/name/ logs "config <name> is NXDOMAIN", and a
	// 0.0.0.0 entry in the addn-hosts blocklist logs "<hostsfile> <name> is
	// 0.0.0.0" (or "::" for an AAAA query). The source is never the network,
	// so these are exactly the lines that mark a lookup as blocked.
	dnsBlocked = regexp.MustCompile(`^(?:config|/\S+)\s+\S+\s+is\s+(?:NXDOMAIN|0\.0\.0\.0|::)$`)
)

// How long a lookup is held open waiting for further lines before being
// written out. Replies follow their query within milliseconds, so this only
// has to outlast the tailer's own poll interval.
const dnsTransactionTTL = 5 * time.Second

type dnsTransaction struct {
	clientIP    string
	queryType   string
	name        string
	forwardedTo []string
	answers     []string
	aliases     []string
	cached      bool
	blocked     bool
	lastSeen    time.Time
}

// dnsCorrelator assembles the separate log lines of a lookup into one record.
// Keyed by dnsmasq's transaction id, which it reuses freely -- a second query
// arriving on an id flushes whatever was still open under it.
type dnsCorrelator struct {
	serviceID int64
	// Takes the assembled row rather than the Store itself, so the parsing
	// can be exercised against real captured log lines without a database.
	emit    func(TrafficLogRow)
	usersFn func() map[string]UserInfo
	pending map[string]*dnsTransaction
}

func newDNSCorrelator(serviceID int64, emit func(TrafficLogRow), usersFn func() map[string]UserInfo) *dnsCorrelator {
	return &dnsCorrelator{
		serviceID: serviceID,
		emit:      emit,
		usersFn:   usersFn,
		pending:   make(map[string]*dnsTransaction),
	}
}

func (c *dnsCorrelator) handleLine(line string, now time.Time) {
	prefix := dnsLinePrefix.FindStringSubmatch(line)
	if prefix == nil {
		return
	}

	id, clientIP, rest := prefix[1], prefix[2], prefix[3]

	if m := dnsQueryPart.FindStringSubmatch(rest); m != nil {
		// Ids are recycled, so anything still open under this one belongs to
		// an earlier lookup and is complete as far as we will ever know.
		if existing, ok := c.pending[id]; ok {
			c.flush(existing)
		}

		c.pending[id] = &dnsTransaction{
			clientIP:  clientIP,
			queryType: m[1],
			name:      m[2],
			lastSeen:  now,
		}

		return
	}

	tx, ok := c.pending[id]
	if !ok {
		return // Started mid-lookup (agent restart, log rotation) -- no query to attach to.
	}

	tx.lastSeen = now

	if m := dnsForwarded.FindStringSubmatch(rest); m != nil {
		tx.forwardedTo = appendUnique(tx.forwardedTo, m[2])

		return
	}

	if dnsBlocked.MatchString(rest) {
		tx.blocked = true

		return
	}

	if m := dnsAnswer.FindStringSubmatch(rest); m != nil {
		kind, name, value := m[1], m[2], m[3]

		if kind == "cached" {
			tx.cached = true
		}

		// dnsmasq writes the literal "<CNAME>" for the alias step itself and
		// then reports the target under the aliased name.
		if value == "<CNAME>" {
			tx.aliases = appendUnique(tx.aliases, name)

			return
		}

		if name != tx.name {
			tx.aliases = appendUnique(tx.aliases, name)
		}

		tx.answers = appendUnique(tx.answers, value)
	}
}

// sweep writes out lookups that have gone quiet. Called on the tailer's own
// tick rather than on a timer of its own.
func (c *dnsCorrelator) sweep(now time.Time) {
	for id, tx := range c.pending {
		if now.Sub(tx.lastSeen) >= dnsTransactionTTL {
			c.flush(tx)
			delete(c.pending, id)
		}
	}
}

func (c *dnsCorrelator) flushAll() {
	for id, tx := range c.pending {
		c.flush(tx)
		delete(c.pending, id)
	}
}

func (c *dnsCorrelator) flush(tx *dnsTransaction) {
	user, ok := c.usersFn()[tx.clientIP]
	if !ok || !user.LoggingEffective {
		return // Unknown peer, or logging is off for this user -- don't record.
	}

	detail := map[string]any{
		"query_type": tx.queryType,
		"cached":     tx.cached,
	}

	// Only carry the legs that actually happened, so a cache hit doesn't
	// record an empty upstream list as if it had asked one.
	if len(tx.forwardedTo) > 0 {
		detail["forwarded_to"] = tx.forwardedTo
	}

	if len(tx.answers) > 0 {
		detail["answers"] = tx.answers
	}

	if len(tx.aliases) > 0 {
		detail["cname_chain"] = tx.aliases
	}

	c.emit(TrafficLogRow{
		ServiceID:     c.serviceID,
		ServiceUserID: nullableID(user.ID),
		Kind:          "dns",
		OccurredAt:    time.Now().UTC(),
		SourceIP:      tx.clientIP,
		Host:          tx.name,
		Blocked:       boolFlag(tx.blocked),
		Detail:        detail,
	})
}

func appendUnique(values []string, value string) []string {
	for _, existing := range values {
		if existing == value {
			return values
		}
	}

	return append(values, value)
}

// TailDNSLog polls a dnsmasq log file for new lines (simple polling rather
// than inotify -- personal-scale usage doesn't need sub-second latency, and
// polling is far simpler to get right across log rotation). usersFn is
// called on every flush so it always sees the latest known set of users,
// without the tailer needing to manage its own refresh timer.
func TailDNSLog(ctx context.Context, path string, serviceID int64, store *Store, usersFn func() map[string]UserInfo) {
	var file *os.File
	var reader *bufio.Reader

	correlator := newDNSCorrelator(serviceID, store.Enqueue, usersFn)

	openFile := func() bool {
		f, err := os.Open(path)
		if err != nil {
			// Silent for "doesn't exist yet" (expected during the startup
			// race with dnsmasq -- next tick will just try again), but a
			// permission error means retrying forever won't help and
			// something's misconfigured -- worth surfacing rather than
			// swallowing, since this exact failure mode (a directory
			// permission regression) previously went unnoticed for a
			// while specifically because nothing logged it.
			if os.IsPermission(err) {
				log.Printf("dns[%d]: permission denied opening %s: %v", serviceID, path, err)
			}

			return false
		}

		// Only new queries from this point on -- an agent restart
		// shouldn't re-import a log file's entire history.
		if _, err := f.Seek(0, io.SeekEnd); err != nil {
			f.Close()

			return false
		}

		file = f
		reader = bufio.NewReader(f)

		return true
	}

	ticker := time.NewTicker(2 * time.Second)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			correlator.flushAll()

			if file != nil {
				file.Close()
			}

			return
		case now := <-ticker.C:
			if file == nil {
				if !openFile() {
					correlator.sweep(now)

					continue // dnsmasq for this service isn't up yet -- retry next tick.
				}
			} else if fi, statErr := os.Stat(path); statErr == nil {
				// dnsmasq recreates this file on every restart (and a
				// logrotate-style rotation would too) -- the currently
				// open fd would keep pointing at the old, now-orphaned
				// inode and silently never see another line again.
				if curFi, curErr := file.Stat(); curErr == nil && !os.SameFile(fi, curFi) {
					file.Close()
					file = nil
					if !openFile() {
						correlator.sweep(now)

						continue
					}
				}
			}

			for {
				line, err := reader.ReadString('\n')
				if line != "" {
					correlator.handleLine(line, now)
				}

				if err != nil {
					if err != io.EOF {
						log.Printf("dns[%d]: read error, reopening: %v", serviceID, err)
						file.Close()
						file = nil
					}

					break
				}
			}

			correlator.sweep(now)
		}
	}
}

func dnsLogPath(dir string, service ServiceInfo) string {
	return fmt.Sprintf("%s/dns-%s.log", dir, service.InterfaceName)
}
