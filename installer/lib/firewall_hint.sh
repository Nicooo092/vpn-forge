#!/bin/bash
#
# Prints what needs opening rather than silently changing firewall rules --
# `ufw`/cloud security groups are often managed outside the box itself
# (e.g. Azure NSGs, AWS security groups), so guessing and auto-applying
# here could easily be wrong or redundant.

print_firewall_hint() {
  print_brake 60
  warning "Make sure these ports are reachable from the internet:"
  output "  22/tcp   - SSH (you're presumably already using this)"
  if [ "$SCHEME" = "https" ]; then
    output "  443/tcp  - the panel (HTTPS)"
    output "  80/tcp   - only needed transiently for Let's Encrypt renewal"
  else
    output "  80/tcp   - the panel (HTTP -- no domain was provided for SSL)"
  fi
  output ""
  output "  Each VPN Service you create in the panel needs its OWN port"
  output "  opened too (shown when you create it) -- this installer has"
  output "  no way to know those in advance, since services are created"
  output "  later, inside the panel."
  output ""
  if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    warning "ufw is active on this host -- you'll also need 'ufw allow' rules"
    warning "for the ports above, in addition to any cloud provider firewall."
  fi
  print_brake 60
}
