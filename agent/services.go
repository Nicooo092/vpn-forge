package main

import (
	"context"
	"log"
	"sync"
	"time"
)

// ServiceWatcher polls the services/service_users tables periodically and
// starts or stops per-service capture goroutines as services are added or
// removed in the panel, so the agent never needs restarting when that set
// changes.
type ServiceWatcher struct {
	store  *Store
	config *Config

	mu      sync.Mutex
	running map[int64]context.CancelFunc
	users   map[int64]map[string]UserInfo
}

func NewServiceWatcher(store *Store, config *Config) *ServiceWatcher {
	return &ServiceWatcher{
		store:   store,
		config:  config,
		running: make(map[int64]context.CancelFunc),
		users:   make(map[int64]map[string]UserInfo),
	}
}

func (w *ServiceWatcher) Run(ctx context.Context) {
	w.refresh(ctx)

	interval := time.Duration(w.config.PollIntervalSeconds) * time.Second
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			w.refresh(ctx)
		}
	}
}

func (w *ServiceWatcher) refresh(ctx context.Context) {
	services, err := w.store.ActiveServices(ctx)
	if err != nil {
		log.Printf("watcher: failed to load services: %v", err)

		return
	}

	w.mu.Lock()
	defer w.mu.Unlock()

	seen := make(map[int64]bool, len(services))

	for _, svc := range services {
		svc := svc // defensive copy, see loop-closure note below
		seen[svc.ID] = true

		users, err := w.store.UsersByTunnelIP(ctx, svc.ID)
		if err != nil {
			log.Printf("watcher: failed to load users for service %d: %v", svc.ID, err)
		} else {
			w.users[svc.ID] = users
		}

		if _, alreadyRunning := w.running[svc.ID]; alreadyRunning {
			continue
		}

		svcCtx, cancel := context.WithCancel(ctx)
		w.running[svc.ID] = cancel

		// Always reads the latest map from w.users, refreshed each
		// poll -- capturing svc.ID (not the map itself) is what keeps
		// this closure correct across refreshes.
		serviceID := svc.ID
		usersFn := func() map[string]UserInfo {
			w.mu.Lock()
			defer w.mu.Unlock()

			return w.users[serviceID]
		}

		go TailDNSLog(svcCtx, dnsLogPath(w.config.DNSLogDir, svc), svc.ID, w.store, usersFn)
		go CaptureHTTP(svcCtx, svc, w.store, usersFn)

		log.Printf("watcher: started capture for service %d (%s)", svc.ID, svc.InterfaceName)
	}

	for id, cancel := range w.running {
		if !seen[id] {
			cancel()
			delete(w.running, id)
			delete(w.users, id)
			log.Printf("watcher: stopped capture for removed service %d", id)
		}
	}
}
