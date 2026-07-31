package main

import (
	"fmt"
	"os"

	"gopkg.in/yaml.v3"
)

// Config is loaded from /etc/vpnforge/agent.yml, written by the installer.
// The agent connects with its own least-privileged database credential
// (INSERT/SELECT on traffic_logs only, plus SELECT on services and
// service_users) -- never the panel's own credential.
type Config struct {
	Database struct {
		Host     string `yaml:"host"`
		Port     int    `yaml:"port"`
		User     string `yaml:"user"`
		Password string `yaml:"password"`
		Database string `yaml:"database"`
	} `yaml:"database"`

	// How often the agent re-reads the services/service_users tables to
	// notice services or users that were added/removed since it started.
	PollIntervalSeconds int `yaml:"poll_interval_seconds"`

	// Where dnsmasq is configured to write each service's query log
	// (installer writes one file per service here, named <interface>.log).
	DNSLogDir string `yaml:"dns_log_dir"`
}

func LoadConfig(path string) (*Config, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading config file %s: %w", path, err)
	}

	var cfg Config
	if err := yaml.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("parsing config file %s: %w", path, err)
	}

	if cfg.PollIntervalSeconds <= 0 {
		cfg.PollIntervalSeconds = 30
	}

	if cfg.DNSLogDir == "" {
		cfg.DNSLogDir = "/var/log/vpnforge"
	}

	return &cfg, nil
}

func (c *Config) DSN() string {
	return fmt.Sprintf(
		"%s:%s@tcp(%s:%d)/%s?parseTime=true&loc=UTC",
		c.Database.User, c.Database.Password, c.Database.Host, c.Database.Port, c.Database.Database,
	)
}
