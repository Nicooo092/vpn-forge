package main

import (
	"context"
	"database/sql"
	"encoding/json"
	"log"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

// ServiceInfo mirrors just the columns the agent needs from the `services`
// table -- it never writes to this table, only reads it (schema ownership
// stays entirely with the Laravel migrations).
type ServiceInfo struct {
	ID            int64
	InterfaceName string
	SubnetCIDR    string
}

// UserInfo mirrors the `service_users` columns needed to attribute a
// packet/query to a user by its tunnel IP, and to resolve the effective
// logging setting (override, falling back to the service default).
type UserInfo struct {
	ID               int64
	TunnelIP         string
	LoggingEffective bool
}

type TrafficLogRow struct {
	ServiceID     int64
	ServiceUserID sql.NullInt64
	Kind          string // "dns" | "http"
	OccurredAt    time.Time
	SourceIP      string
	Host          string
	Detail        map[string]any
}

type Store struct {
	db *sql.DB

	mu       sync.Mutex
	buffer   []TrafficLogRow
	flushMax int
}

func NewStore(dsn string) (*Store, error) {
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}

	db.SetMaxOpenConns(4)
	db.SetConnMaxLifetime(5 * time.Minute)

	if err := db.Ping(); err != nil {
		return nil, err
	}

	return &Store{db: db, flushMax: 200}, nil
}

func (s *Store) Close() error {
	return s.db.Close()
}

// ActiveServices returns every service currently known to the panel. The
// agent doesn't care about ServiceStatus beyond "does it exist" -- an
// inactive/errored service simply won't have a live interface to watch,
// and the tailer/sniffer goroutines handle a missing interface gracefully.
func (s *Store) ActiveServices(ctx context.Context) ([]ServiceInfo, error) {
	rows, err := s.db.QueryContext(ctx, `SELECT id, interface_name, subnet_cidr FROM services`)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var services []ServiceInfo
	for rows.Next() {
		var svc ServiceInfo
		if err := rows.Scan(&svc.ID, &svc.InterfaceName, &svc.SubnetCIDR); err != nil {
			return nil, err
		}
		services = append(services, svc)
	}

	return services, rows.Err()
}

// UsersByTunnelIP returns every user of a service keyed by tunnel IP, along
// with their effective logging setting, so the caller can decide whether a
// given packet/query should be recorded at all before ever calling Enqueue.
func (s *Store) UsersByTunnelIP(ctx context.Context, serviceID int64) (map[string]UserInfo, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT su.id, su.tunnel_ip, COALESCE(su.logging_override, s.logging_enabled_default) AS effective
		FROM service_users su
		INNER JOIN services s ON s.id = su.service_id
		WHERE su.service_id = ? AND su.status = 'active'
	`, serviceID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	users := make(map[string]UserInfo)
	for rows.Next() {
		var u UserInfo
		var effective bool
		if err := rows.Scan(&u.ID, &u.TunnelIP, &effective); err != nil {
			return nil, err
		}
		u.LoggingEffective = effective
		users[u.TunnelIP] = u
	}

	return users, rows.Err()
}

// Enqueue buffers a row for the next batched flush rather than doing one
// round-trip per DNS query / HTTP request.
func (s *Store) Enqueue(row TrafficLogRow) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.buffer = append(s.buffer, row)

	if len(s.buffer) >= s.flushMax {
		batch := s.buffer
		s.buffer = nil
		go s.flush(batch)
	}
}

// FlushLoop periodically flushes whatever has been buffered, even if the
// batch size threshold hasn't been hit, so rows don't sit unflushed
// indefinitely on a quiet connection.
func (s *Store) FlushLoop(ctx context.Context, interval time.Duration) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			s.mu.Lock()
			batch := s.buffer
			s.buffer = nil
			s.mu.Unlock()
			s.flush(batch)
			return
		case <-ticker.C:
			s.mu.Lock()
			batch := s.buffer
			s.buffer = nil
			s.mu.Unlock()
			s.flush(batch)
		}
	}
}

func (s *Store) flush(batch []TrafficLogRow) {
	if len(batch) == 0 {
		return
	}

	tx, err := s.db.Begin()
	if err != nil {
		log.Printf("store: failed to begin transaction: %v", err)

		return
	}

	stmt, err := tx.Prepare(`
		INSERT INTO traffic_logs (service_id, service_user_id, kind, occurred_at, source_ip, host, detail)
		VALUES (?, ?, ?, ?, ?, ?, ?)
	`)
	if err != nil {
		log.Printf("store: failed to prepare insert: %v", err)
		_ = tx.Rollback()

		return
	}
	defer stmt.Close()

	for _, row := range batch {
		detailJSON, err := json.Marshal(row.Detail)
		if err != nil {
			log.Printf("store: failed to marshal detail for row: %v", err)

			continue
		}

		if _, err := stmt.Exec(row.ServiceID, row.ServiceUserID, row.Kind, row.OccurredAt, row.SourceIP, row.Host, detailJSON); err != nil {
			log.Printf("store: failed to insert traffic log row: %v", err)
		}
	}

	if err := tx.Commit(); err != nil {
		log.Printf("store: failed to commit batch of %d rows: %v", len(batch), err)
	}
}
