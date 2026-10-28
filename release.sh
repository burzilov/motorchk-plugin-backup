#!/usr/bin/env bash
# Релиз плагина: проверки → аннотированный тег v<version> → архив dist/backup-<version>.zip из тега.
#   ./release.sh          — тег и архив (локально)
#   ./release.sh --push   — то же и git push origin HEAD + тег (если у репозитория есть origin)
# Проверки: рабочее дерево чистое (нет незакоммиченных и неотслеживаемых файлов вне .gitignore);
# первый заголовок CHANGELOG.md («## X.Y.Z — дата») совпадает с version из plugin.json.
# Тег уже есть: на HEAD — архив пересобирается, на другом коммите — отказ (версию нужно поднять).
# Архив — git archive тега, поэтому в него попадает только закоммиченное; распаковать в public_html/plugins/.
set -euo pipefail
cd "$(dirname "$0")"

id=backup
files=(plugin.json plugin.php bin lib templates assets README.md CHANGELOG.md LICENSE)
push=false
case "${1:-}" in
    --push) push=true ;;
    '') ;;
    *) echo "Использование: ./release.sh [--push]" >&2; exit 2 ;;
esac

[[ -z "$(git status --porcelain)" ]] || { echo "Рабочее дерево не чистое — закоммитьте или уберите изменения:" >&2; git status --short >&2; exit 1; }

# Первое совпадение — sed …;q без `| head -n1`: под pipefail ранний выход head давал бы SIGPIPE (exit 141) на большом файле
version=$(sed -n '/^[[:space:]]*"version":[[:space:]]*"[^"]*"/{s/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p;q;}' plugin.json)
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Не удалось прочитать version из plugin.json" >&2; exit 1; }
heading=$(sed -n '/^## /{s/^## \([^ ]*\).*/\1/p;q;}' CHANGELOG.md)
[[ "$heading" == "$version" ]] || { echo "Первая запись CHANGELOG.md — «${heading:-нет}», а в plugin.json — $version" >&2; exit 1; }

tag="v$version"
head=$(git rev-parse HEAD)
if git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
    [[ "$(git rev-parse "$tag^{commit}")" == "$head" ]] || { echo "Тег $tag уже стоит на другом коммите — поднимите версию" >&2; exit 1; }
    echo "Тег $tag уже на HEAD"
else
    git tag -a "$tag" -m "$id $version"
    echo "Создан тег $tag"
fi

mkdir -p dist
out="dist/$id-$version.zip"
git archive --format=zip --prefix="$id/" -o "$out" "$tag" -- "${files[@]}"
echo "Архив: $out"

if $push; then
    git remote get-url origin >/dev/null 2>&1 || { echo "У репозитория нет origin — тег и коммиты остаются локальными" >&2; exit 1; }
    git push origin HEAD
    git push origin "$tag"
fi
