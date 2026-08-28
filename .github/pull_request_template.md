## Checklist

- [ ] `bash tools/check.sh` passes locally
- [ ] AMD build committed if `amd/src` changed
- [ ] `version.php` bumped if needed
- [ ] `$plugin->supported` matches CI matrix
- [ ] Light: leak gate patterns (`bash tools/grep-light-leaks.sh .`)
