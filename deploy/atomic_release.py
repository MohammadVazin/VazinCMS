#!/usr/bin/env python3
"""Root-only durable atomic directory switch helper.

This helper never starts services and never reads application secrets.  The
installer owns health checks; this helper owns path validation, durable state,
atomic rename steps and conservative recovery after signals, SIGKILL or power
loss.
"""
from __future__ import annotations

import argparse
import ctypes
import errno
import hashlib
import json
import os
import re
import sqlite3
import stat
import sys
import tempfile
from pathlib import Path
from typing import Any

SEMVER = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
TOKEN = re.compile(r"^[A-Za-z0-9._-]{1,160}$")
SCHEMA = 2
MANUAL = 70
RUNTIME_BASE = Path("/var/lib/vazincms-runtime")


def fail(message: str, code: int = 1) -> "None":
    print(message, file=sys.stderr)
    raise SystemExit(code)


def machine_binding() -> str:
    try:
        raw = Path("/etc/machine-id").read_bytes().strip()
    except OSError as error:
        fail(f"machine binding unavailable: {error}", MANUAL)
    if not raw:
        fail("machine binding is empty", MANUAL)
    return hashlib.sha256(raw).hexdigest()


def fsync_dir(path: Path) -> None:
    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0)
    descriptor = os.open(path, flags)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def path_parts(path: Path) -> list[Path]:
    path = Path(os.path.abspath(path))
    if not path.is_absolute():
        fail("path must be absolute")
    parts = [Path(path.anchor)]
    current = Path(path.anchor)
    for component in path.parts[1:]:
        current /= component
        parts.append(current)
    return parts


def secure_chain(path: Path, *, final_owner_root: bool = True) -> None:
    parts = path_parts(path)
    for index, component in enumerate(parts):
        try:
            info = os.lstat(component)
        except FileNotFoundError:
            fail(f"secure path component is missing: {component}")
        if stat.S_ISLNK(info.st_mode):
            fail(f"symbolic-link path component rejected: {component}")
        if index < len(parts) - 1 and not stat.S_ISDIR(info.st_mode):
            fail(f"non-directory path ancestor rejected: {component}")
        require_root = index < len(parts) - 1 or final_owner_root
        if os.name == "posix" and require_root and info.st_uid != 0:
            fail(f"non-root-owned path component rejected: {component}")
        if info.st_mode & 0o022:
            fail(f"group/other-writable path component rejected: {component}")


def secure_tree(root: Path) -> None:
    secure_chain(root)
    for current, directories, files in os.walk(root, followlinks=False):
        current_path = Path(current)
        for name in [*directories, *files]:
            child = current_path / name
            info = os.lstat(child)
            if stat.S_ISLNK(info.st_mode):
                fail(f"release symbolic link rejected: {child}")
            if os.name == "posix" and info.st_uid != 0:
                fail(f"non-root-owned release entry rejected: {child}")
            if info.st_mode & 0o022:
                fail(f"writable release entry rejected: {child}")


def secure_live_tree(root: Path, mutable_names: list[str]) -> None:
    """Validate immutable code while allowing exact top-level runtime names."""
    secure_chain(root);allowed=set(mutable_names)
    if not allowed or any(TOKEN.fullmatch(name) is None or "/" in name for name in allowed):fail("invalid live-tree exception")
    for current,directories,files in os.walk(root,topdown=True,followlinks=False):
        current_path=Path(current);relative=current_path.relative_to(root)
        if relative==Path('.'):
            for name in list(directories):
                child=current_path/name;info=os.lstat(child)
                if name in allowed:
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or info.st_mode&0o022:fail(f"unsafe mutable directory: {child}")
                    directories.remove(name)
            for name in files:
                child=current_path/name;info=os.lstat(child)
                if name in allowed:
                    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o022:fail(f"unsafe mutable file: {child}")
        for name in [*directories,*files]:
            if relative==Path('.') and name in allowed:continue
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode):fail(f"live code symbolic link rejected: {child}")
            if os.name=="posix" and info.st_uid!=0:fail(f"non-root-owned live code rejected: {child}")
            if info.st_mode&0o022:fail(f"writable live code rejected: {child}")


def secure_cms_live_tree(root: Path,uid: int,gid: int) -> None:
    secure_chain(root)
    environment=root/".env";info=os.lstat(environment)
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or (os.name=="posix" and (info.st_uid,info.st_gid)!=(0,gid)) or stat.S_IMODE(info.st_mode)!=0o640:
        fail("live CMS environment owner/mode mismatch",MANUAL)
    seal_service_pair(root/"storage",root/"public"/"uploads",uid,gid)
    for current,directories,files in os.walk(root,topdown=True,followlinks=False):
        current_path=Path(current);relative=current_path.relative_to(root)
        if relative==Path('.') and "storage" in directories:directories.remove("storage")
        if relative==Path('public') and "uploads" in directories:directories.remove("uploads")
        for name in [*directories,*files]:
            child=current_path/name
            if child==environment:continue
            child_info=os.lstat(child)
            if stat.S_ISLNK(child_info.st_mode):fail(f"live code symbolic link rejected: {child}",MANUAL)
            if os.name=="posix" and child_info.st_uid!=0:fail(f"non-root-owned live code rejected: {child}",MANUAL)
            if child_info.st_mode&0o022:fail(f"writable live code rejected: {child}",MANUAL)


def ensure_dir(path: Path, parent: Path) -> None:
    path = Path(os.path.abspath(path)); parent = Path(os.path.abspath(parent))
    if path.parent != parent:
        fail("only an exact one-level secure directory may be provisioned")
    secure_chain(parent)
    try:
        os.mkdir(path, 0o700)
        os.chown(path, 0, 0)
        fsync_dir(parent)
    except FileExistsError:
        pass
    secure_chain(path)
    info = os.lstat(path)
    if not stat.S_ISDIR(info.st_mode):
        fail(f"secure directory is not a directory: {path}")
    os.chmod(path, 0o700)
    fsync_dir(path)


def ensure_runtime_root(path: Path,parent: Path,gid: int) -> None:
    path=Path(os.path.abspath(path));parent=Path(os.path.abspath(parent))
    if path.parent!=parent or not isinstance(gid,int) or gid<0:fail("invalid runtime-root provisioning request")
    secure_chain(parent);created=False
    try:os.mkdir(path,0o700);created=True;fsync_dir(parent)
    except FileExistsError:pass
    info=os.lstat(path)
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o022:
        fail("unsafe external runtime root",MANUAL)
    descriptor=os.open(path,os.O_RDONLY|getattr(os,"O_DIRECTORY",0)|getattr(os,"O_NOFOLLOW",0))
    try:
        current=os.fstat(descriptor)
        if (current.st_dev,current.st_ino)!=(info.st_dev,info.st_ino):fail("runtime root changed during validation",MANUAL)
        os.fchown(descriptor,0,gid);os.fchmod(descriptor,0o750);os.fsync(descriptor)
    finally:os.close(descriptor)
    fsync_dir(parent);secure_chain(path)


def ensure_service_dir(path: Path,parent: Path,uid: int,gid: int) -> None:
    path=Path(os.path.abspath(path));parent=Path(os.path.abspath(parent))
    if path.parent!=parent or any(not isinstance(value,int) or value<0 for value in (uid,gid)):
        fail("invalid service-directory provisioning request")
    secure_chain(parent);created=False
    try:os.mkdir(path,0o700);created=True
    except FileExistsError:pass
    info=os.lstat(path)
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or info.st_mode&0o022:
        fail("unsafe external service directory",MANUAL)
    if created:
        descriptor=os.open(path,os.O_RDONLY|getattr(os,"O_DIRECTORY",0)|getattr(os,"O_NOFOLLOW",0))
        try:os.fchown(descriptor,uid,gid);os.fchmod(descriptor,0o700);os.fsync(descriptor)
        finally:os.close(descriptor)
        fsync_dir(parent);info=os.lstat(path)
    if os.name=="posix" and (info.st_uid!=uid or info.st_gid!=gid):fail("external service directory ownership mismatch",MANUAL)
    if stat.S_IMODE(info.st_mode)!=0o700:fail("external service directory mode mismatch",MANUAL)
    fsync_dir(path)


def ensure_runtime_generation(path: Path,parent: Path,uid: int,gid: int) -> None:
    path=Path(os.path.abspath(path));parent=Path(os.path.abspath(parent))
    if path.parent!=parent:fail("invalid runtime generation path")
    secure_chain(parent)
    try:
        os.mkdir(path,0o750);os.chown(path,0,gid);fsync_dir(parent)
    except FileExistsError:pass
    info=os.lstat(path)
    if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o022:
        fail("unsafe CMS runtime generation",MANUAL)
    os.chown(path,0,gid);os.chmod(path,0o750);fsync_dir(path);fsync_dir(parent)
    ensure_service_dir(path/"storage",path,uid,gid)
    ensure_service_dir(path/"uploads",path,uid,gid)


def create_child(root: Path, prefix: str) -> Path:
    secure_chain(root)
    if not TOKEN.fullmatch(prefix):
        fail("unsafe backup child prefix")
    child = Path(tempfile.mkdtemp(prefix=prefix, dir=root))
    os.chmod(child, 0o700)
    if os.name == "posix":
        os.chown(child, 0, 0)
    fsync_dir(child); fsync_dir(root)
    secure_chain(child)
    return child


def create_service_child(root: Path,prefix: str,uid: int,gid: int) -> Path:
    secure_chain(root)
    if not TOKEN.fullmatch(prefix) or any(not isinstance(value,int) or value<0 for value in (uid,gid)):
        fail("unsafe service child request")
    child=Path(tempfile.mkdtemp(prefix=prefix,dir=root));descriptor=os.open(child,os.O_RDONLY|getattr(os,"O_DIRECTORY",0)|getattr(os,"O_NOFOLLOW",0))
    try:os.fchown(descriptor,uid,gid);os.fchmod(descriptor,0o700);os.fsync(descriptor)
    finally:os.close(descriptor)
    fsync_dir(root);return child


def create_runtime_generation(root: Path,prefix: str,uid: int,gid: int) -> Path:
    secure_chain(root)
    if not TOKEN.fullmatch(prefix) or any(not isinstance(value,int) or value<0 for value in (uid,gid)):
        fail("unsafe CMS runtime generation request")
    child=Path(tempfile.mkdtemp(prefix=prefix,dir=root));os.chown(child,0,gid);os.chmod(child,0o750);fsync_dir(root)
    ensure_service_dir(child/"storage",child,uid,gid);ensure_service_dir(child/"uploads",child,uid,gid)
    return child


def fsync_file(path: Path) -> None:
    info = os.lstat(path)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
        fail(f"regular file required for fsync: {path}")
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def seal_tree(root: Path) -> None:
    secure_tree(root)
    directories: list[Path] = []
    for current, child_dirs, files in os.walk(root, followlinks=False):
        current_path = Path(current); directories.append(current_path)
        for name in files:
            fsync_file(current_path / name)
        for name in child_dirs:
            child = current_path / name
            if stat.S_ISLNK(os.lstat(child).st_mode):
                fail(f"symbolic link rejected while sealing: {child}")
    for directory in reversed(directories):
        fsync_dir(directory)
    fsync_dir(root.parent)


def read_version(path: Path) -> str | None:
    version_file = path / "VERSION"
    try:
        info = os.lstat(version_file)
        if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
            return None
        value = version_file.read_text(encoding="utf-8").strip()
    except OSError:
        return None
    return value if SEMVER.fullmatch(value) else None


def snapshot(path: Path, version: str) -> dict[str, Any]:
    info = os.lstat(path)
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        fail(f"snapshot path is unsafe: {path}")
    if read_version(path) != version:
        fail(f"VERSION mismatch at {path}")
    return {"path": str(path), "version": version, "device": info.st_dev, "inode": info.st_ino}


def file_sha256(path: Path) -> str:
    digest=hashlib.sha256();flags=os.O_RDONLY|getattr(os,"O_NOFOLLOW",0)
    descriptor=os.open(path,flags)
    try:
        before=os.fstat(descriptor)
        if not stat.S_ISREG(before.st_mode):fail(f"regular file required for hashing: {path}")
        with os.fdopen(descriptor,"rb",closefd=False) as stream:
            for block in iter(lambda:stream.read(1024*1024),b""):digest.update(block)
        after=os.fstat(descriptor)
        if (before.st_dev,before.st_ino,before.st_size)!=(after.st_dev,after.st_ino,after.st_size):
            fail(f"file changed while hashing: {path}",MANUAL)
    finally:os.close(descriptor)
    return digest.hexdigest()


def manifest_matches(root: Path, expected: str) -> bool:
    if not isinstance(expected,str) or re.fullmatch(r"[a-f0-9]{64}",expected) is None:return False
    try:
        secure_chain(root);manifest=root/"MANIFEST.sha256";info=os.lstat(manifest)
        if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):return False
        if os.name=="posix" and info.st_uid!=0:return False
        if info.st_mode&0o022:return False
        if file_sha256(manifest)!=expected:return False
        seen:set[str]=set()
        for raw in manifest.read_text(encoding="utf-8").splitlines():
            match=re.fullmatch(r"([a-f0-9]{64}) [ *]([^\x00-\x1f\x7f]+)",raw)
            if match is None:return False
            digest,name=match.groups()
            if name in seen or "\\" in name:return False
            relative=Path(name)
            if relative.is_absolute() or name in {"","."} or any(part in {"",".",".."} for part in relative.parts):return False
            candidate=root/relative
            try:candidate.relative_to(root)
            except ValueError:return False
            candidate_info=os.lstat(candidate)
            if not stat.S_ISREG(candidate_info.st_mode) or stat.S_ISLNK(candidate_info.st_mode) or file_sha256(candidate)!=digest:return False
            seen.add(name)
        return bool(seen) and "VERSION" in seen and "deploy/atomic_release.py" in seen
    except (OSError,SystemExit):return False


def backup_snapshot(path: Path) -> dict[str, Any]:
    path=Path(os.path.abspath(path));backup_root=Path("/var/backups")
    try:path.relative_to(backup_root)
    except ValueError:fail("backup reference is outside /var/backups")
    secure_chain(path.parent)
    info=os.lstat(path)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o077:
        fail(f"unsafe backup reference: {path}")
    return{"path":str(path),"sha256":file_sha256(path),"size":info.st_size,"device":info.st_dev,"inode":info.st_ino}


def backup_matches(item: dict[str,Any]) -> bool:
    if not isinstance(item,dict) or set(item)!={"path","sha256","size","device","inode"}:return False
    if not isinstance(item["path"],str) or not re.fullmatch(r"[a-f0-9]{64}",str(item["sha256"])):return False
    if not all(isinstance(item[key],int) and item[key]>=0 for key in ("size","device","inode")):return False
    try:
        current=backup_snapshot(Path(item["path"]))
    except (OSError,SystemExit):return False
    return current==item


def directory_identity(path: Path) -> dict[str, int]:
    info=os.lstat(path)
    if not stat.S_ISDIR(info.st_mode) or stat.S_ISLNK(info.st_mode):
        fail(f"runtime path is not a real directory: {path}")
    if os.name=="posix" and info.st_mode&0o022:
        fail(f"runtime directory is group/other writable: {path}")
    return {"device":info.st_dev,"inode":info.st_ino}


def directory_identity_matches(item: Any,path: Path) -> bool:
    if not isinstance(item,dict) or set(item)!={"device","inode"}:
        return False
    if any(not isinstance(item[key],int) or item[key]<0 for key in item):
        return False
    try:return directory_identity(path)==item
    except (OSError,SystemExit):return False


def runtime_permissions(root: Path,storage_name: str,uploads_name: str,uid: int,gid: int) -> None:
    root_info=os.lstat(root)
    if (os.name=="posix" and (root_info.st_uid,root_info.st_gid)!=(0,gid)) or stat.S_IMODE(root_info.st_mode)!=0o750:
        fail("runtime generation owner/mode mismatch",MANUAL)
    for path in (root/storage_name,root/uploads_name):
        info=os.lstat(path)
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and (info.st_uid,info.st_gid)!=(uid,gid)) or stat.S_IMODE(info.st_mode)!=0o700:
            fail("runtime child owner/mode mismatch",MANUAL)


def mutable_tree_snapshot(root: Path,storage_name: str,uploads_name: str,uid: int,gid: int) -> dict[str,Any]:
    """Bind one offline CMS runtime generation without storing its contents."""
    secure_chain(root.parent);identity=directory_identity(root)
    if not TOKEN.fullmatch(storage_name) or not TOKEN.fullmatch(uploads_name) or storage_name==uploads_name:
        fail("invalid runtime directory names",MANUAL)
    runtime_permissions(root,storage_name,uploads_name,uid,gid)
    digest=hashlib.sha256();seen:set[str]=set()
    for current,directories,files in os.walk(root,topdown=True,followlinks=False):
        current_path=Path(current)
        for name in sorted(directories):
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and info.st_mode&0o022):
                fail(f"unsafe runtime directory entry: {child}",MANUAL)
            if current_path!=root and os.name=="posix" and (info.st_uid,info.st_gid)!=(uid,gid):
                fail(f"runtime directory owner mismatch: {child}",MANUAL)
        for name in sorted(files):
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or (os.name=="posix" and info.st_mode&0o022):
                fail(f"unsafe runtime file entry: {child}",MANUAL)
            if current_path==root or (os.name=="posix" and (info.st_uid,info.st_gid)!=(uid,gid)):
                fail(f"runtime file owner mismatch: {child}",MANUAL)
            relative=child.relative_to(root).as_posix()
            if relative in seen or any(part in {"",".",".."} for part in Path(relative).parts):
                fail("unsafe duplicate runtime entry",MANUAL)
            seen.add(relative);file_digest=file_sha256(child)
            digest.update(relative.encode("utf-8")+b"\0"+str(info.st_size).encode("ascii")+b"\0"+file_digest.encode("ascii")+b"\n")
    storage=root/storage_name;uploads=root/uploads_name
    for path in (storage,uploads):
        info=os.lstat(path)
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or info.st_mode&0o022:
            fail("runtime storage/uploads pair is incomplete",MANUAL)
    return {**identity,"tree_sha256":digest.hexdigest(),"storage":storage_name,"uploads":uploads_name,"uid":uid,"gid":gid}


def seal_mutable_tree(root: Path) -> None:
    """Durably seal a service-owned tree without making it immutable."""
    secure_chain(root.parent);directory_identity(root);directories:list[Path]=[]
    for current,child_dirs,files in os.walk(root,followlinks=False):
        current_path=Path(current);directories.append(current_path)
        for name in child_dirs:
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and info.st_mode&0o022):
                fail(f"unsafe mutable directory while sealing: {child}",MANUAL)
        for name in files:
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or (os.name=="posix" and info.st_mode&0o022):
                fail(f"unsafe mutable file while sealing: {child}",MANUAL)
            fsync_file(child)
    for directory in reversed(directories):fsync_dir(directory)
    fsync_dir(root.parent)


def seal_service_pair(storage: Path,uploads: Path,uid: int,gid: int) -> None:
    if storage.name!="storage" or uploads.name!="uploads" or storage.parent!=uploads.parent.parent:
        fail("invalid legacy runtime pair",MANUAL)
    for root in (storage,uploads):
        info=os.lstat(root)
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or (os.name=="posix" and (info.st_uid,info.st_gid)!=(uid,gid)) or stat.S_IMODE(info.st_mode)!=0o700:
            fail("legacy runtime root owner/mode mismatch",MANUAL)
        directories=[]
        for current,child_dirs,files in os.walk(root,followlinks=False):
            current_path=Path(current);directories.append(current_path)
            for name in child_dirs:
                child=current_path/name;child_info=os.lstat(child)
                if stat.S_ISLNK(child_info.st_mode) or not stat.S_ISDIR(child_info.st_mode) or (os.name=="posix" and (child_info.st_uid,child_info.st_gid)!=(uid,gid)) or child_info.st_mode&0o022:
                    fail("unsafe legacy runtime directory",MANUAL)
            for name in files:
                child=current_path/name;child_info=os.lstat(child)
                if stat.S_ISLNK(child_info.st_mode) or not stat.S_ISREG(child_info.st_mode) or (os.name=="posix" and (child_info.st_uid,child_info.st_gid)!=(uid,gid)) or child_info.st_mode&0o022:
                    fail("unsafe legacy runtime file",MANUAL)
                fsync_file(child)
        for directory in reversed(directories):fsync_dir(directory)
    fsync_dir(storage.parent);fsync_dir(uploads.parent)


def content_tree_sha256(root: Path) -> str:
    """Hash mutable content without binding it to one deployment pathname."""
    digest=hashlib.sha256()
    for current,child_dirs,files in os.walk(root,topdown=True,followlinks=False):
        current_path=Path(current)
        for name in sorted(child_dirs):
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode):
                fail("unsafe mutable directory while hashing",MANUAL)
            relative=child.relative_to(root).as_posix()
            digest.update(b"D\0"+relative.encode("utf-8")+b"\n")
        for name in sorted(files):
            child=current_path/name;info=os.lstat(child)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode):
                fail("unsafe mutable file while hashing",MANUAL)
            relative=child.relative_to(root).as_posix()
            digest.update(b"F\0"+relative.encode("utf-8")+b"\0"+str(info.st_size).encode("ascii")+b"\0"+file_sha256(child).encode("ascii")+b"\n")
    return digest.hexdigest()


def service_pair_content(storage: Path,uploads: Path,uid: int,gid: int) -> dict[str,Any]:
    seal_service_pair(storage,uploads,uid,gid)
    return {
        "storage_sha256":content_tree_sha256(storage),
        "uploads_sha256":content_tree_sha256(uploads),
    }


def active_pair_content(runtime: dict[str,Any]) -> dict[str,Any]:
    active=Path(runtime["active"])
    runtime_permissions(active,Path(runtime["runtime_storage"]).name,Path(runtime["runtime_uploads"]).name,runtime["uid"],runtime["gid"])
    return {
        "storage_sha256":content_tree_sha256(Path(runtime["runtime_storage"])),
        "uploads_sha256":content_tree_sha256(Path(runtime["runtime_uploads"])),
    }


def legacy_recovery_matches(item: Any,target: Path,runtime: dict[str,Any]) -> bool:
    if not isinstance(item,dict) or set(item)!={"slot_identity","storage_sha256","uploads_sha256"}:
        return False
    if not isinstance(item["slot_identity"],dict) or set(item["slot_identity"])!={"device","inode"}:
        return False
    if any(not isinstance(item[key],str) or re.fullmatch(r"[a-f0-9]{64}",item[key]) is None for key in ("storage_sha256","uploads_sha256")):
        return False
    try:
        if not directory_identity_matches(item["slot_identity"],target):return False
        legacy=service_pair_content(target/"storage",target/"public"/"uploads",runtime["uid"],runtime["gid"])
        active=active_pair_content(runtime)
        return legacy==active=={"storage_sha256":item["storage_sha256"],"uploads_sha256":item["uploads_sha256"]}
    except (OSError,SystemExit):return False


def mutable_tree_matches(item: Any,path: Path) -> bool:
    if not isinstance(item,dict) or set(item)!={"device","inode","tree_sha256","storage","uploads","uid","gid"}:
        return False
    if any(not isinstance(item[key],int) or item[key]<0 for key in ("device","inode")):
        return False
    if not isinstance(item["tree_sha256"],str) or re.fullmatch(r"[a-f0-9]{64}",item["tree_sha256"]) is None:
        return False
    if any(not isinstance(item[key],str) or TOKEN.fullmatch(item[key]) is None for key in ("storage","uploads")):
        return False
    if any(not isinstance(item[key],int) or item[key]<0 for key in ("uid","gid")):return False
    try:return mutable_tree_snapshot(path,item["storage"],item["uploads"],item["uid"],item["gid"])==item
    except (OSError,SystemExit):return False


def bound_generation_snapshot(root: Path,storage_name: str,uploads_name: str,uid: int,gid: int) -> dict[str,Any]:
    """Bind immutable directory identities while allowing CMS content writes."""
    runtime_permissions(root,storage_name,uploads_name,uid,gid)
    identity=directory_identity(root);storage=root/storage_name;uploads=root/uploads_name
    storage_identity=directory_identity(storage);uploads_identity=directory_identity(uploads)
    database=storage/"database.sqlite";sqlite_item=None
    if database.exists() or database.is_symlink():
        info=os.lstat(database)
        if stat.S_ISLNK(info.st_mode) or not stat.S_ISREG(info.st_mode) or info.st_size<1 or info.st_mode&0o022:
            fail("unsafe bound SQLite database",MANUAL)
        connection=sqlite3.connect("file:"+str(database)+"?mode=ro",uri=True)
        try:check=connection.execute("PRAGMA quick_check").fetchone()
        finally:connection.close()
        if not check or check[0]!="ok":fail("bound runtime database quick_check failed",MANUAL)
        sqlite_item={"device":info.st_dev,"inode":info.st_ino}
    return {**identity,"storage":storage_name,"storage_identity":storage_identity,
            "uploads":uploads_name,"uploads_identity":uploads_identity,"sqlite":sqlite_item,"uid":uid,"gid":gid}


def bound_generation_matches(item: Any,path: Path) -> bool:
    required={"device","inode","storage","storage_identity","uploads","uploads_identity","sqlite","uid","gid"}
    if not isinstance(item,dict) or set(item)!=required:return False
    for key in ("device","inode","uid","gid"):
        if not isinstance(item[key],int) or item[key]<0:return False
    if any(not isinstance(item[key],str) or TOKEN.fullmatch(item[key]) is None for key in ("storage","uploads")):return False
    if not all(isinstance(item[key],dict) and set(item[key])=={"device","inode"} for key in ("storage_identity","uploads_identity")):return False
    if item["sqlite"] is not None and (not isinstance(item["sqlite"],dict) or set(item["sqlite"])!={"device","inode"}):return False
    try:return bound_generation_snapshot(path,item["storage"],item["uploads"],item["uid"],item["gid"])==item
    except (OSError,sqlite3.Error,SystemExit):return False


def read_path_settings(environment: Path) -> tuple[str|None,str|None]:
    info=os.lstat(environment)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_size>1024*1024:
        fail("unsafe runtime environment file",MANUAL)
    values:dict[str,str]={}
    with environment.open(encoding="utf-8") as stream:
        for raw in stream:
            line=raw.strip()
            if not line or line.startswith("#") or "=" not in line:continue
            key,value=line.split("=",1)
            if key.strip() in {"VAZINCMS_STORAGE_PATH","VAZINCMS_UPLOADS_PATH"}:
                values[key.strip()]=value.strip().strip("\"'")
    return values.get("VAZINCMS_STORAGE_PATH"),values.get("VAZINCMS_UPLOADS_PATH")


def runtime_environment_state(environment: Path,runtime: dict[str,Any]) -> str:
    try:database,key=read_path_settings(environment)
    except (OSError,SystemExit):return "invalid"
    pair=(database,key)
    if pair==(None,None) or pair==(runtime["legacy_storage"],runtime["legacy_uploads"]):return "legacy"
    if pair==(runtime["runtime_storage"],runtime["runtime_uploads"]):return "external"
    return "invalid"


def rename_exchange(left: Path,right: Path) -> None:
    """Linux renameat2(RENAME_EXCHANGE), with no non-atomic fallback."""
    libc=ctypes.CDLL(None,use_errno=True)
    function=getattr(libc,"renameat2",None)
    if function is None:fail("renameat2 is unavailable; refusing non-atomic fallback")
    function.argtypes=[ctypes.c_int,ctypes.c_char_p,ctypes.c_int,ctypes.c_char_p,ctypes.c_uint]
    function.restype=ctypes.c_int
    result=function(-100,os.fsencode(left),-100,os.fsencode(right),2)
    if result!=0:
        code=ctypes.get_errno()
        fail(f"atomic RENAME_EXCHANGE failed with errno {code}: {os.strerror(code)}")


def probe_exchange(parent: Path) -> None:
    secure_chain(parent)
    left=Path(tempfile.mkdtemp(prefix=".vazin-exchange-a-",dir=parent));right=Path(tempfile.mkdtemp(prefix=".vazin-exchange-b-",dir=parent))
    try:
        left_inode=os.lstat(left).st_ino;right_inode=os.lstat(right).st_ino
        rename_exchange(left,right);fsync_dir(parent)
        if os.lstat(left).st_ino!=right_inode or os.lstat(right).st_ino!=left_inode:fail("RENAME_EXCHANGE inode verification failed")
        rename_exchange(left,right);fsync_dir(parent)
        if os.lstat(left).st_ino!=left_inode or os.lstat(right).st_ino!=right_inode:fail("RENAME_EXCHANGE restore verification failed")
    finally:
        for path in (left,right):
            try:os.rmdir(path)
            except FileNotFoundError:pass
        fsync_dir(parent)


def marker_path(state_dir: Path) -> Path:
    return state_dir / "deploy-in-progress.json"


def completed_path(state_dir: Path) -> Path:
    return state_dir / "last-completed.json"


def durable_json(path: Path, payload: dict[str, Any]) -> None:
    state_dir = path.parent; secure_chain(state_dir)
    descriptor, temporary = tempfile.mkstemp(prefix=".deploy-in-progress.", dir=state_dir)
    try:
        os.fchmod(descriptor, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(payload, stream, sort_keys=True, separators=(",", ":"))
            stream.write("\n"); stream.flush(); os.fsync(stream.fileno())
        os.replace(temporary, path); fsync_dir(state_dir)
    except BaseException:
        try: os.close(descriptor)
        except OSError: pass
        try: os.unlink(temporary)
        except FileNotFoundError: pass
        raise


def clear_marker(state_dir: Path) -> None:
    marker = marker_path(state_dir)
    try:
        info = os.lstat(marker)
        if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
            fail("unsafe deployment marker", MANUAL)
        os.unlink(marker); fsync_dir(state_dir)
    except FileNotFoundError:
        return


def validate_payload(payload: Any, state_dir: Path, target: Path,
                     old_version: str, new_version: str) -> dict[str, Any]:
    required = {"schema","correlation_id","phase","machine","target","fresh","previous","failed","old","new","manifest_sha256","backup_refs","runtime"}
    if not isinstance(payload, dict) or set(payload) != required or payload.get("schema") != SCHEMA:
        fail("invalid deployment journal schema", MANUAL)
    for key in ("old","new"):
        item=payload.get(key)
        if not isinstance(item,dict) or set(item)!={"path","version","device","inode"}:
            fail("invalid deployment journal snapshot",MANUAL)
        if not isinstance(item["path"],str) or not isinstance(item["version"],str) or not SEMVER.fullmatch(item["version"]):
            fail("invalid deployment journal snapshot values",MANUAL)
        if not isinstance(item["device"],int) or not isinstance(item["inode"],int) or item["device"]<0 or item["inode"]<0:
            fail("invalid deployment journal snapshot identity",MANUAL)
    if payload["phase"] not in {"prepared","swapped"} or payload["machine"] != machine_binding():
        fail("deployment journal binding mismatch", MANUAL)
    if payload["target"] != str(target) or payload["old"].get("version") != old_version or payload["new"].get("version") != new_version:
        fail("deployment journal target/version mismatch", MANUAL)
    if not TOKEN.fullmatch(str(payload["correlation_id"])) or not re.fullmatch(r"[a-f0-9]{64}",str(payload["manifest_sha256"])):
        fail("invalid deployment journal correlation/manifest", MANUAL)
    for key in ("fresh","previous","failed"):
        if not isinstance(payload[key],str):fail("deployment journal contains a non-string path",MANUAL)
        candidate=Path(payload[key])
        if candidate.parent != target.parent or not candidate.name.startswith("."+target.name+"."):
            fail("deployment journal contains an unsafe path", MANUAL)
    if payload["old"]["path"]!=str(target) or payload["new"]["path"]!=payload["fresh"]:
        fail("deployment journal snapshot path mismatch",MANUAL)
    if not isinstance(payload["backup_refs"],list) or not payload["backup_refs"] or any(not backup_matches(item) for item in payload["backup_refs"]):
        fail("deployment journal backup references are invalid", MANUAL)
    runtime=payload["runtime"]
    if runtime is not None:
        runtime_keys={"mode","phase","active","prepared","active_initial","prepared_initial","ready","bound","legacy_recovery","legacy_storage","legacy_uploads","runtime_storage","runtime_uploads","uid","gid"}
        if not isinstance(runtime,dict) or set(runtime)!=runtime_keys:
            fail("invalid runtime migration journal",MANUAL)
        if runtime["mode"] not in {"legacy","existing"} or runtime["phase"] not in {"declared","ready","switched","bound"}:
            fail("invalid runtime migration phase",MANUAL)
        for key in ("active","legacy_storage","legacy_uploads","runtime_storage","runtime_uploads"):
            if not isinstance(runtime[key],str) or not Path(runtime[key]).is_absolute():fail("invalid runtime migration path",MANUAL)
        active=Path(runtime["active"]);prepared=Path(runtime["prepared"]) if runtime["prepared"] is not None else None
        if any(not isinstance(runtime[key],int) or runtime[key]<0 for key in ("uid","gid")):fail("invalid runtime service identity",MANUAL)
        runtime_base=RUNTIME_BASE
        if active.parent.parent!=runtime_base or active.name!="data" or TOKEN.fullmatch(active.parent.name) is None:
            fail("runtime migration active path is not the exact external data directory",MANUAL)
        if Path(runtime["runtime_storage"])!=active/"storage" or Path(runtime["runtime_uploads"])!=active/"uploads":
            fail("runtime paths escape the active generation",MANUAL)
        if Path(runtime["legacy_storage"])!=target/"storage" or Path(runtime["legacy_uploads"])!=target/"public"/"uploads":
            fail("legacy paths escape the live release",MANUAL)
        if (not directory_identity_matches(runtime["active_initial"],active) and
                not mutable_tree_matches(runtime["ready"],active) and
                not bound_generation_matches(runtime["bound"],active)):
            fail("runtime active generation identity is unexpected",MANUAL)
        if runtime["mode"]=="existing":
            if prepared is not None or runtime["prepared_initial"] is not None or runtime["phase"]!="bound" or not bound_generation_matches(runtime["bound"],active):
                fail("invalid existing-runtime binding",MANUAL)
        else:
            if prepared is None or prepared.parent!=active.parent or not prepared.name.startswith(".data.new-"+payload["correlation_id"]):
                fail("unsafe prepared runtime generation",MANUAL)
            if runtime["prepared_initial"] is None or runtime["phase"]=="declared" and runtime["ready"] is not None:
                fail("invalid prepared runtime snapshot",MANUAL)
            # Either side may carry the ready inode after an atomic exchange.
            known=(directory_identity_matches(runtime["prepared_initial"],prepared) or
                   directory_identity_matches(runtime["active_initial"],prepared) or
                   mutable_tree_matches(runtime["ready"],prepared))
            if not known:fail("prepared runtime generation identity is unexpected",MANUAL)
            if runtime["phase"] in {"ready","switched","bound"} and runtime["ready"] is None:
                fail("runtime ready snapshot is missing",MANUAL)
        if runtime["phase"]=="bound":
            if not bound_generation_matches(runtime["bound"],active):fail("bound runtime generation is invalid",MANUAL)
        elif runtime["bound"] is not None:fail("premature bound runtime snapshot",MANUAL)
        recovery=runtime["legacy_recovery"]
        if recovery is not None:
            if runtime["phase"]!="bound" or not isinstance(recovery,dict) or set(recovery)!={"slot_identity","storage_sha256","uploads_sha256"}:
                fail("invalid legacy recovery snapshot",MANUAL)
            if not isinstance(recovery["slot_identity"],dict) or set(recovery["slot_identity"])!={"device","inode"}:
                fail("invalid legacy recovery slot identity",MANUAL)
            if any(not isinstance(recovery["slot_identity"][key],int) or recovery["slot_identity"][key]<0 for key in ("device","inode")):
                fail("invalid legacy recovery inode binding",MANUAL)
            if any(not isinstance(recovery[key],str) or re.fullmatch(r"[a-f0-9]{64}",recovery[key]) is None for key in ("storage_sha256","uploads_sha256")):
                fail("invalid legacy recovery content binding",MANUAL)
    return payload


def load_marker(state_dir: Path, target: Path, old_version: str,
                new_version: str) -> dict[str, Any]:
    secure_chain(state_dir); marker=marker_path(state_dir)
    info=os.lstat(marker)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o077:
        fail("unsafe deployment journal",MANUAL)
    try: payload=json.loads(marker.read_text(encoding="utf-8"))
    except (OSError,json.JSONDecodeError): fail("unreadable deployment journal",MANUAL)
    return validate_payload(payload,state_dir,target,old_version,new_version)


def load_completed(state_dir: Path,target: Path,old_version: str,new_version: str) -> dict[str,Any]:
    secure_chain(state_dir);receipt=completed_path(state_dir)
    info=os.lstat(receipt)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or (os.name=="posix" and info.st_uid!=0) or info.st_mode&0o077:
        fail("unsafe completed-release receipt",MANUAL)
    try:wrapper=json.loads(receipt.read_text(encoding="utf-8"))
    except (OSError,json.JSONDecodeError):fail("unreadable completed-release receipt",MANUAL)
    if not isinstance(wrapper,dict) or set(wrapper)!={"schema","kind","machine","payload"} or wrapper.get("schema")!=1 or wrapper.get("kind")!="vazincms-completed-release" or wrapper.get("machine")!=machine_binding():
        fail("invalid completed-release receipt",MANUAL)
    payload=validate_payload(wrapper.get("payload"),state_dir,target,old_version,new_version)
    if payload["phase"]!="swapped":fail("completed-release receipt has an invalid phase",MANUAL)
    return payload


def matches(item: dict[str, Any], path: Path) -> bool:
    try: info=os.lstat(path)
    except FileNotFoundError: return False
    return stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode) and info.st_dev==item["device"] and info.st_ino==item["inode"] and read_version(path)==item["version"]


def command_begin(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);fresh=Path(args.fresh);previous=Path(args.previous);failed=Path(args.failed)
    secure_chain(state);secure_chain(target.parent)
    if marker_path(state).exists() or marker_path(state).is_symlink():fail("deployment journal already exists",MANUAL)
    if previous.exists() or previous.is_symlink() or failed.exists() or failed.is_symlink():fail("deployment recovery path already exists")
    if fresh.parent!=target.parent or previous.parent!=target.parent or failed.parent!=target.parent:fail("atomic paths must share the target parent")
    old=snapshot(target,args.old_version);new=snapshot(fresh,args.new_version)
    if not manifest_matches(fresh,args.manifest_sha256):fail("candidate manifest binding mismatch",MANUAL)
    backups=[backup_snapshot(Path(item)) for item in args.backup_ref]
    if not backups:fail("at least one verified backup reference is required")
    runtime=None
    runtime_fields=[args.runtime_active,args.legacy_storage,args.legacy_uploads,args.runtime_storage,args.runtime_uploads,args.service_uid,args.service_gid]
    if any(item is not None for item in runtime_fields):
        if any(item is None for item in runtime_fields):fail("all runtime pair arguments are required",MANUAL)
        active=Path(os.path.abspath(args.runtime_active));legacy_storage=Path(os.path.abspath(args.legacy_storage));legacy_uploads=Path(os.path.abspath(args.legacy_uploads))
        runtime_storage=Path(os.path.abspath(args.runtime_storage));runtime_uploads=Path(os.path.abspath(args.runtime_uploads))
        secure_chain(active.parent);active_initial=directory_identity(active)
        prepared=None;prepared_initial=None;ready=None;bound=None;mode="existing";runtime_phase="bound"
        if args.runtime_prepared is not None:
            prepared=Path(os.path.abspath(args.runtime_prepared));prepared_initial=directory_identity(prepared)
            mode="legacy";runtime_phase="declared"
            if runtime_environment_state(target/".env",{
                "legacy_storage":str(legacy_storage),"legacy_uploads":str(legacy_uploads),
                "runtime_storage":str(runtime_storage),"runtime_uploads":str(runtime_uploads),
            })!="legacy":fail("legacy runtime declaration does not match live environment",MANUAL)
        else:
            provisional={"legacy_storage":str(legacy_storage),"legacy_uploads":str(legacy_uploads),"runtime_storage":str(runtime_storage),"runtime_uploads":str(runtime_uploads)}
            if runtime_environment_state(target/".env",provisional)!="external":fail("existing runtime is not bound by live environment",MANUAL)
            ready=mutable_tree_snapshot(active,runtime_storage.name,runtime_uploads.name,args.service_uid,args.service_gid)
            bound=bound_generation_snapshot(active,runtime_storage.name,runtime_uploads.name,args.service_uid,args.service_gid)
        runtime={"mode":mode,"phase":runtime_phase,"active":str(active),"prepared":str(prepared) if prepared else None,
                 "active_initial":active_initial,"prepared_initial":prepared_initial,"ready":ready,"bound":bound,"legacy_recovery":None,
                 "legacy_storage":str(legacy_storage),"legacy_uploads":str(legacy_uploads),
                 "runtime_storage":str(runtime_storage),"runtime_uploads":str(runtime_uploads),"uid":args.service_uid,"gid":args.service_gid}
    payload={"schema":SCHEMA,"correlation_id":args.correlation_id,"phase":"prepared","machine":machine_binding(),"target":str(target),"fresh":str(fresh),"previous":str(previous),"failed":str(failed),"old":old,"new":new,"manifest_sha256":args.manifest_sha256,"backup_refs":backups,"runtime":runtime}
    durable_json(marker_path(state),payload)


def command_runtime_ready(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version);runtime=payload["runtime"]
    if not isinstance(runtime,dict) or runtime["mode"]!="legacy" or runtime["phase"]!="declared":fail("runtime-ready phase mismatch",MANUAL)
    active=Path(runtime["active"]);prepared=Path(runtime["prepared"])
    if not directory_identity_matches(runtime["active_initial"],active) or not directory_identity_matches(runtime["prepared_initial"],prepared):
        fail("runtime-ready directory identity mismatch",MANUAL)
    seal_mutable_tree(prepared)
    runtime["ready"]=mutable_tree_snapshot(prepared,Path(runtime["runtime_storage"]).name,Path(runtime["runtime_uploads"]).name,runtime["uid"],runtime["gid"])
    runtime["phase"]="ready";durable_json(marker_path(state),payload)


def command_runtime_switch(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version);runtime=payload["runtime"]
    if not isinstance(runtime,dict) or runtime["mode"]!="legacy" or runtime["phase"]!="ready":fail("runtime-switch phase mismatch",MANUAL)
    active=Path(runtime["active"]);prepared=Path(runtime["prepared"])
    if not directory_identity_matches(runtime["active_initial"],active) or not mutable_tree_matches(runtime["ready"],prepared):
        fail("runtime-switch generation mismatch",MANUAL)
    rename_exchange(active,prepared);fsync_dir(active.parent)
    if not mutable_tree_matches(runtime["ready"],active) or not directory_identity_matches(runtime["active_initial"],prepared):
        fail("runtime exchange verification failed",MANUAL)
    runtime["phase"]="switched";durable_json(marker_path(state),payload)


def command_runtime_bind(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version);runtime=payload["runtime"]
    if not isinstance(runtime,dict):fail("runtime-bind requires a runtime journal",MANUAL)
    if runtime["mode"]=="existing":return
    if runtime["phase"]!="switched" or not mutable_tree_matches(runtime["ready"],Path(runtime["active"])):
        fail("runtime-bind generation mismatch",MANUAL)
    if runtime_environment_state(target/".env",runtime)!="legacy" or runtime_environment_state(Path(payload["fresh"])/".env",runtime)!="external":
        fail("old/new release runtime bindings are not exact",MANUAL)
    runtime["bound"]=bound_generation_snapshot(Path(runtime["active"]),Path(runtime["runtime_storage"]).name,Path(runtime["runtime_uploads"]).name,runtime["uid"],runtime["gid"])
    runtime["phase"]="bound";durable_json(marker_path(state),payload)


def command_runtime_recovery_ready(args: argparse.Namespace) -> None:
    """Bind an exact hydrated legacy slot before rolling code back to it."""
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version);runtime=payload["runtime"]
    if runtime is None or runtime["mode"]!="legacy" or runtime["phase"]!="bound":
        print("not-required");return
    active=Path(runtime["active"])
    if not bound_generation_matches(runtime["bound"],active):fail("runtime recovery active generation mismatch",MANUAL)
    candidates=[target,Path(payload["fresh"]),Path(payload["previous"])]
    found=[path for path in candidates if matches(payload["old"],path)]
    if len(found)!=1:fail("runtime recovery old slot is ambiguous",MANUAL)
    slot=found[0]
    legacy=service_pair_content(slot/"storage",slot/"public"/"uploads",runtime["uid"],runtime["gid"])
    active_content=active_pair_content(runtime)
    if legacy!=active_content:fail("hydrated legacy runtime does not match the active pair",MANUAL)
    runtime["legacy_recovery"]={"slot_identity":directory_identity(slot),**legacy}
    durable_json(marker_path(state),payload);print("ready")


def command_swap(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version)
    fresh=Path(payload["fresh"]);previous=Path(payload["previous"])
    if payload["phase"]!="prepared" or not matches(payload["old"],target) or not matches(payload["new"],fresh) or previous.exists() or previous.is_symlink():
        fail("atomic swap precondition mismatch",MANUAL)
    runtime=payload["runtime"]
    if runtime is not None:
        if runtime["phase"]!="bound" or not bound_generation_matches(runtime["bound"],Path(runtime["active"])):
            fail("external runtime is not durably bound before code swap",MANUAL)
        target_state=runtime_environment_state(target/".env",runtime)
        fresh_state=runtime_environment_state(fresh/".env",runtime)
        if runtime["mode"]=="legacy":
            if target_state!="legacy" or fresh_state!="external":
                fail("legacy runtime bindings are not exact before code swap",MANUAL)
        elif runtime["mode"]=="existing":
            if target_state!="external" or fresh_state!="external":
                fail("existing external runtime bindings are not exact before code swap",MANUAL)
        else:
            fail("unknown runtime mode before code swap",MANUAL)
    if not manifest_matches(fresh,payload["manifest_sha256"]):fail("candidate manifest changed after journal",MANUAL)
    rename_exchange(target,fresh);fsync_dir(target.parent)
    if not matches(payload["new"],target) or not matches(payload["old"],fresh):fail("atomic exchange verification failed",MANUAL)
    os.rename(fresh,previous);fsync_dir(target.parent)
    payload["phase"]="swapped";durable_json(marker_path(state),payload)


def command_recover(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);marker=marker_path(state)
    if not marker.exists() and not marker.is_symlink():print("none");return
    payload=load_marker(state,target,args.old_version,args.new_version)
    previous=Path(payload["previous"]);failed=Path(payload["failed"]);fresh=Path(payload["fresh"])
    def recover_runtime() -> None:
        runtime=payload["runtime"]
        if runtime is None:return
        environment_state=runtime_environment_state(target/".env",runtime)
        if environment_state=="external":
            # SIGKILL window: the old release .env was atomically/fsync-bound
            # after the runtime exchange, but runtime-bind had not yet written
            # phase=bound.  Exact ready/old-generation inodes prove which side
            # won.  Finish that binding durably instead of entering MANUAL.
            if runtime["bound"] is None:
                active=Path(runtime["active"]);prepared=Path(runtime["prepared"]) if runtime["prepared"] is not None else None
                if (
                    runtime["mode"]!="legacy" or runtime["phase"]!="switched"
                    or prepared is None
                    or not mutable_tree_matches(runtime["ready"],active)
                    or not directory_identity_matches(runtime["active_initial"],prepared)
                ):
                    fail("external runtime binding is not safely finishable",MANUAL)
                runtime["bound"]=bound_generation_snapshot(active,Path(runtime["runtime_storage"]).name,Path(runtime["runtime_uploads"]).name,runtime["uid"],runtime["gid"])
                runtime["phase"]="bound";durable_json(marker_path(state),payload)
            if not bound_generation_matches(runtime["bound"],Path(runtime["active"])):
                fail("external runtime binding has an unexpected generation",MANUAL)
            return
        if environment_state=="legacy" and runtime["mode"]=="legacy" and runtime["phase"]=="bound":
            # The old release may not understand the external uploads path.
            # Its exact slot is hydrated while public writes are stopped, then
            # both copies are content-bound in the durable journal.  Keep the
            # external generation intact so a retry can copy the same latest
            # state without rolling a mutable tree back to an old hash.
            if not bound_generation_matches(runtime["bound"],Path(runtime["active"])) or not legacy_recovery_matches(runtime["legacy_recovery"],target,runtime):
                fail("legacy runtime recovery was not durably hydrated",MANUAL)
            return
        if environment_state!="legacy" or runtime["mode"]!="legacy":
            fail("runtime environment binding is ambiguous",MANUAL)
        active=Path(runtime["active"]);prepared=Path(runtime["prepared"])
        # A crash after the atomic runtime exchange but before the environment
        # bind is recognized from exact inodes and reversed atomically.
        if runtime["ready"] is not None and mutable_tree_matches(runtime["ready"],active) and directory_identity_matches(runtime["active_initial"],prepared):
            rename_exchange(active,prepared);fsync_dir(active.parent)
        if not directory_identity_matches(runtime["active_initial"],active):
            fail("legacy runtime recovery found an unexpected active generation",MANUAL)
        if runtime["phase"] in {"switched","bound"} and runtime["ready"] is not None and not mutable_tree_matches(runtime["ready"],prepared):
            fail("legacy runtime recovery lost the prepared pair",MANUAL)

    if matches(payload["old"],target) and matches(payload["new"],fresh) and not previous.exists() and not previous.is_symlink():
        recover_runtime();clear_marker(state);print("unchanged");return
    # Crash after exchange but before old-path rename: exchange back atomically.
    if matches(payload["new"],target) and matches(payload["old"],fresh) and not previous.exists() and not previous.is_symlink():
        rename_exchange(target,fresh);fsync_dir(target.parent)
        if not matches(payload["old"],target) or not matches(payload["new"],fresh):fail("exchange recovery verification failed",MANUAL)
        recover_runtime();clear_marker(state);print("restored");return
    # Resume exact crashes inside the rollback half of recovery.  The first
    # state is after target/previous exchange; the second is after archiving
    # the failed new release but before runtime recovery/marker clear.
    if matches(payload["old"],target) and matches(payload["new"],previous) and not failed.exists() and not failed.is_symlink():
        os.rename(previous,failed);fsync_dir(target.parent)
        if not matches(payload["old"],target) or not matches(payload["new"],failed):fail("resumed recovery archive verification failed",MANUAL)
        recover_runtime();clear_marker(state);print("restored");return
    if matches(payload["old"],target) and matches(payload["new"],failed) and not previous.exists() and not previous.is_symlink():
        recover_runtime();clear_marker(state);print("restored");return
    if not matches(payload["old"],previous) or not matches(payload["new"],target) or failed.exists() or failed.is_symlink():
        fail("unexpected target state; manual recovery required",MANUAL)
    # Once old was moved to previous, exchange target/previous keeps TARGET
    # continuously present; the now-new previous path is archived as failed.
    rename_exchange(target,previous);fsync_dir(target.parent)
    if not matches(payload["old"],target) or not matches(payload["new"],previous):fail("rollback exchange verification failed",MANUAL)
    os.rename(previous,failed);fsync_dir(target.parent)
    if not matches(payload["old"],target):fail("restored deployment verification failed",MANUAL)
    recover_runtime();clear_marker(state);print("restored")


def command_complete(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);payload=load_marker(state,target,args.old_version,args.new_version)
    previous=Path(payload["previous"])
    if payload["phase"]!="swapped" or not matches(payload["new"],target) or not matches(payload["old"],previous):
        fail("cannot complete an unverified deployment",MANUAL)
    if not manifest_matches(target,payload["manifest_sha256"]):fail("deployed manifest binding mismatch",MANUAL)
    runtime=payload["runtime"]
    if runtime is not None and (runtime["phase"]!="bound" or runtime_environment_state(target/".env",runtime)!="external" or not bound_generation_matches(runtime["bound"],Path(runtime["active"]))):
        fail("cannot complete without the exact external runtime pair",MANUAL)
    # The completed receipt is fsynced before the in-progress marker is
    # removed.  It is sufficient to reconstruct the exact recovery journal
    # for a later code-only rollback without copying mutable runtime state.
    durable_json(completed_path(state),{
        "schema":1,"kind":"vazincms-completed-release",
        "machine":machine_binding(),"payload":payload,
    })
    clear_marker(state)


def command_rollback_begin(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target);marker=marker_path(state)
    if marker.exists() or marker.is_symlink():
        fail("a pending release journal must be recovered before rollback",MANUAL)
    payload=load_completed(state,target,args.old_version,args.new_version)
    previous=Path(payload["previous"]);failed=Path(payload["failed"])
    runtime=payload["runtime"]
    if runtime is not None and (
        runtime["phase"]!="bound"
        or runtime_environment_state(target/".env",runtime)!="external"
        or not bound_generation_matches(runtime["bound"],Path(runtime["active"]))
    ):
        fail("completed release no longer has its exact external runtime pair",MANUAL)
    # Idempotent rerun after recovery: the old code is live and the rolled-back
    # new generation is archived at the journal-bound failed path.
    if matches(payload["old"],target) and matches(payload["new"],failed) and not previous.exists() and not previous.is_symlink():
        print("already");return
    if not matches(payload["new"],target) or not matches(payload["old"],previous) or failed.exists() or failed.is_symlink():
        fail("completed release paths do not match the exact rollback receipt",MANUAL)
    if not manifest_matches(target,payload["manifest_sha256"]):
        fail("deployed release changed after completion",MANUAL)
    durable_json(marker,payload);print("ready")


def command_old_slot(args: argparse.Namespace) -> None:
    state=Path(args.state);target=Path(args.target)
    payload=load_marker(state,target,args.old_version,args.new_version)
    candidates=[target,Path(payload["fresh"]),Path(payload["previous"])]
    found=[path for path in candidates if matches(payload["old"],path)]
    if len(found)!=1:fail("old release slot is ambiguous",MANUAL)
    print(found[0])


def command_completed_status(args: argparse.Namespace) -> None:
    payload=load_completed(Path(args.state),Path(args.target),args.old_version,args.new_version)
    if not matches(payload["new"],Path(args.target)):fail("completed release is not live",MANUAL)
    print("complete")


def parser() -> argparse.ArgumentParser:
    result=argparse.ArgumentParser()
    sub=result.add_subparsers(dest="command",required=True)
    one=sub.add_parser("validate-source");one.add_argument("path")
    one=sub.add_parser("validate-live");one.add_argument("path");one.add_argument("mutable",nargs="+")
    one=sub.add_parser("validate-cms-live");one.add_argument("path");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("ensure-dir");one.add_argument("path");one.add_argument("parent")
    one=sub.add_parser("ensure-runtime-root");one.add_argument("path");one.add_argument("parent");one.add_argument("gid",type=int)
    one=sub.add_parser("ensure-service-dir");one.add_argument("path");one.add_argument("parent");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("ensure-runtime-generation");one.add_argument("path");one.add_argument("parent");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("create-child");one.add_argument("root");one.add_argument("prefix")
    one=sub.add_parser("create-service-child");one.add_argument("root");one.add_argument("prefix");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("create-runtime-generation");one.add_argument("root");one.add_argument("prefix");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("fsync-file");one.add_argument("path")
    one=sub.add_parser("seal-tree");one.add_argument("path")
    one=sub.add_parser("seal-service-pair");one.add_argument("storage");one.add_argument("uploads");one.add_argument("uid",type=int);one.add_argument("gid",type=int)
    one=sub.add_parser("probe-exchange");one.add_argument("parent")
    one=sub.add_parser("status");one.add_argument("state")
    for name in ("recover","swap","complete","rollback-begin","runtime-ready","runtime-switch","runtime-bind","runtime-recovery-ready","old-slot","completed-status"):
        one=sub.add_parser(name);one.add_argument("state");one.add_argument("target");one.add_argument("old_version");one.add_argument("new_version")
    one=sub.add_parser("begin")
    for field in ("state","target","fresh","previous","failed","old_version","new_version","correlation_id","manifest_sha256"):one.add_argument(field)
    one.add_argument("--runtime-active");one.add_argument("--runtime-prepared")
    one.add_argument("--legacy-storage");one.add_argument("--legacy-uploads")
    one.add_argument("--runtime-storage");one.add_argument("--runtime-uploads")
    one.add_argument("--service-uid",type=int);one.add_argument("--service-gid",type=int)
    one.add_argument("backup_ref",nargs="*")
    return result


def main() -> int:
    if os.name!="posix" or os.geteuid()!=0:fail("atomic release helper requires Linux root")
    args=parser().parse_args()
    if args.command=="validate-source":secure_tree(Path(args.path))
    elif args.command=="validate-live":secure_live_tree(Path(args.path),args.mutable)
    elif args.command=="validate-cms-live":secure_cms_live_tree(Path(args.path),args.uid,args.gid)
    elif args.command=="ensure-dir":ensure_dir(Path(args.path),Path(args.parent))
    elif args.command=="ensure-runtime-root":ensure_runtime_root(Path(args.path),Path(args.parent),args.gid)
    elif args.command=="ensure-service-dir":ensure_service_dir(Path(args.path),Path(args.parent),args.uid,args.gid)
    elif args.command=="ensure-runtime-generation":ensure_runtime_generation(Path(args.path),Path(args.parent),args.uid,args.gid)
    elif args.command=="create-child":print(create_child(Path(args.root),args.prefix))
    elif args.command=="create-service-child":print(create_service_child(Path(args.root),args.prefix,args.uid,args.gid))
    elif args.command=="create-runtime-generation":print(create_runtime_generation(Path(args.root),args.prefix,args.uid,args.gid))
    elif args.command=="fsync-file":fsync_file(Path(args.path));fsync_dir(Path(args.path).parent)
    elif args.command=="seal-tree":seal_tree(Path(args.path))
    elif args.command=="seal-service-pair":seal_service_pair(Path(args.storage),Path(args.uploads),args.uid,args.gid)
    elif args.command=="probe-exchange":probe_exchange(Path(args.parent))
    elif args.command=="status":
        marker=marker_path(Path(args.state));print("pending" if marker.exists() or marker.is_symlink() else "none")
    elif args.command=="begin":command_begin(args)
    elif args.command=="swap":command_swap(args)
    elif args.command=="recover":command_recover(args)
    elif args.command=="complete":command_complete(args)
    elif args.command=="rollback-begin":command_rollback_begin(args)
    elif args.command=="runtime-ready":command_runtime_ready(args)
    elif args.command=="runtime-switch":command_runtime_switch(args)
    elif args.command=="runtime-bind":command_runtime_bind(args)
    elif args.command=="runtime-recovery-ready":command_runtime_recovery_ready(args)
    elif args.command=="old-slot":command_old_slot(args)
    elif args.command=="completed-status":command_completed_status(args)
    return 0


if __name__=="__main__":raise SystemExit(main())
