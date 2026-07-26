# Security Policy

Please report suspected vulnerabilities privately to the maintainers rather than opening a public issue.

Cache storage is not a trust boundary. Keep filesystem directories private, protect Redis with network controls and authentication, and never deserialize attacker-controlled NativeSerializer payloads.
