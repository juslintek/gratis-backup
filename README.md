# Gratis Backup

Stream-first WordPress backup engine. Part of the [Gratis Suite](https://github.com/juslintek/gratis-suite).

## Features (planned)
- Chunked streaming (never loads full backup into memory)
- Hash-while-streaming integrity verification
- Multiple destinations: local, SFTP, S3, Google Drive, Dropbox
- Backup types: full, database-only, uploads, themes/plugins, changed-files
- Rotation policies
- Encryption adapter interface
- Pre-update and pre-builder-publish snapshots
- Integration with cache-manager file-change tracking

## Architecture
See [gratis-suite/ARCHITECTURE.md](https://github.com/juslintek/gratis-suite/blob/main/ARCHITECTURE.md)

## License
GPL-2.0-or-later
