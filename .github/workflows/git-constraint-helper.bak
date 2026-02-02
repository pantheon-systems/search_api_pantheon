get_current_constraint() {
  branch=$(git rev-parse --abbrev-ref HEAD | tr -d '[:space:]')
  if [ "$branch" != "HEAD" ]; then
    echo "${branch}-dev"
    return
  fi

  tag=$(git describe --exact-match --tags "$(git log -n1 --pretty='%h')" 2>/dev/null | tr -d '[:space:]')
  if [ -n "$tag" ]; then
    echo "$tag"
    return
  fi

  if [ -n "$GITHUB_HEAD_REF" ]; then
    IFS='/' read -ra parts <<< "$GITHUB_HEAD_REF"
    branch="${parts[-1]}"
    if [ -n "$branch" ]; then
      echo "${branch}-dev"
      return
    fi
  fi

  echo "^8"
}
