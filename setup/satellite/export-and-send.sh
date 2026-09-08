#!/usr/bin/env bash
set -euo pipefail

# Run this script on the Red Hat Satellite server.
config_file="${MOONCRATER_EXPORT_CONFIG:-/etc/mooncrater-satellite-export.conf}"
if [[ -r $config_file ]]; then
    # This root-owned file contains only deployment settings, never application DB credentials.
    source "$config_file"
fi
mooncrater_host="${MOONCRATER_HOST:-}"
mooncrater_user="${MOONCRATER_SSH_USER:-mooncrater-import}"
mooncrater_inbox="${MOONCRATER_INBOX:-/opt/mooncrater/satellite-import-csv}"
mooncrater_ssh_key="${MOONCRATER_SSH_KEY:-/root/.ssh/mooncrater_export_ed25519}"
if [[ -z $mooncrater_host ]]; then
    printf 'ERROR: MOONCRATER_HOST is not configured in %s.\n' "$config_file" >&2
    exit 2
fi
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
timestamp="$(date +%d%m%Y-%H%M)"
output_file="${script_dir}/export_satellite_completo-${timestamp}.csv"
remote_name="$(basename "$output_file")"
remote_temporary=".${remote_name}.part.$$"

cleanup() { rm -f -- "${output_file}.tmp"; }
trap cleanup EXIT

printf '%s\n' 'hostname;os;ip;kernel;content_view_environment;location;last_checkin' > "${output_file}.tmp"
printf 'Satellite export started: %s\n' "$output_file"

count=0
while IFS=, read -r id name os group ip mac status cv_env rest; do
    [[ $name == *virt-who* ]] && continue
    ((count += 1))
    printf '[%d] Processing: %s ... ' "$count" "$name"

    kernel="$(hammer --csv host facts --id "$id" --search 'name = uname::release' | tail -n 1 | awk -F',' '{print $NF}')"
    host_info="$(hammer host info --id "$id")"
    location="$(grep 'Location:' <<< "$host_info" | head -n 1 | cut -d: -f2- | xargs || true)"
    checkin="$(grep 'Last Checkin:' <<< "$host_info" | head -n 1 | cut -d: -f2- | xargs || true)"

    [[ -n $ip ]] || ip='N/A'
    [[ -n $kernel ]] || kernel='N/A'
    [[ -n $location ]] || location='N/A'
    [[ -n $checkin ]] || checkin='N/A'
    printf '%s;%s;%s;%s;%s;%s;%s\n' "$name" "$os" "$ip" "$kernel" "$cv_env" "$location" "$checkin" >> "${output_file}.tmp"
    printf 'OK (Loc: %s)\n' "$location"
done < <(hammer --csv host list --per-page all | tail -n +2)

mv -- "${output_file}.tmp" "$output_file"
printf 'Export completed: %d hosts. Transferring to %s...\n' "$count" "$mooncrater_host"

# The temporary remote suffix prevents MoonCrater from reading an incomplete SCP transfer.
scp -o BatchMode=yes -o IdentitiesOnly=yes -i "$mooncrater_ssh_key" -- \
    "$output_file" "${mooncrater_user}@${mooncrater_host}:${mooncrater_inbox}/${remote_temporary}"
ssh -o BatchMode=yes -o IdentitiesOnly=yes -i "$mooncrater_ssh_key" -- \
    "${mooncrater_user}@${mooncrater_host}" \
    "mv -- '${mooncrater_inbox}/${remote_temporary}' '${mooncrater_inbox}/${remote_name}'"

printf 'Transfer completed: %s/%s\n' "$mooncrater_inbox" "$remote_name"
