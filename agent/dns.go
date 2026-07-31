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

// dnsmasq is run with --log-queries=extra, which (across the versions this
// was checked against) always includes a "query[TYPE] domain from client_ip"
// segment somewhere on the line -- matching on that substring rather than
// fixed column positions is deliberately more tolerant of the extra
// transaction-id prefix "extra" mode adds versus plain query logging.
var dnsQueryLine = regexp.MustCompile(`query\[(\w+)\]\s+(\S+)\s+from\s+(\S+)`)

// TailDNSLog polls a dnsmasq log file for new lines (simple polling rather
// than inotify -- personal-scale usage doesn't need sub-second latency, and
// polling is far simpler to get right across log rotation). usersFn is
// called on every line so it always sees the latest known set of users,
// without the tailer needing to manage its own refresh timer.
func TailDNSLog(ctx context.Context, path string, serviceID int64, store *Store, usersFn func() map[string]UserInfo) {
	var file *os.File
	var reader *bufio.Reader

	openFile := func() bool {
		f, err := os.Open(path)
		if err != nil {
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
			if file != nil {
				file.Close()
			}

			return
		case <-ticker.C:
			if file == nil {
				if !openFile() {
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
						continue
					}
				}
			}

			for {
				line, err := reader.ReadString('\n')
				if line != "" {
					handleDNSLine(line, serviceID, store, usersFn())
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
		}
	}
}

func handleDNSLine(line string, serviceID int64, store *Store, users map[string]UserInfo) {
	matches := dnsQueryLine.FindStringSubmatch(line)
	if matches == nil {
		return
	}

	queryType := matches[1]
	domain := matches[2]
	clientIP := matches[3]

	user, ok := users[clientIP]
	if !ok || !user.LoggingEffective {
		return // Unknown peer, or logging is off for this user -- don't record.
	}

	store.Enqueue(TrafficLogRow{
		ServiceID:     serviceID,
		ServiceUserID: nullableID(user.ID),
		Kind:          "dns",
		OccurredAt:    time.Now().UTC(),
		SourceIP:      clientIP,
		Host:          domain,
		Detail: map[string]any{
			"query_type": queryType,
		},
	})
}

func dnsLogPath(dir string, service ServiceInfo) string {
	return fmt.Sprintf("%s/dns-%s.log", dir, service.InterfaceName)
}
