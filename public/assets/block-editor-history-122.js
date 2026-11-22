/* Candidate-only in-memory block history. No storage, network or credentials.
   At most 50 undo transitions; conservatively budget UTF-16 snapshot memory. */
((host)=>{
'use strict';
class BlockHistory {
  constructor(snapshot,{maxEntries=51,maxBytes=4*1024*1024,groupMs=700}={}){
    if(typeof snapshot!=='string')throw new TypeError('history_snapshot_must_be_string');
    if(!Number.isInteger(maxEntries)||maxEntries<2||maxEntries>501 ||
       !Number.isInteger(maxBytes)||maxBytes<128||maxBytes>32*1024*1024 ||
       !Number.isInteger(groupMs)||groupMs<0||groupMs>5000)
      throw new RangeError('history_limits_invalid');
    this.entries=[snapshot];this.index=0;this.bytes=snapshot.length*2;
    this.maxEntries=maxEntries;this.maxBytes=maxBytes;this.groupMs=groupMs;
    this.lastKey=null;this.lastAt=0;
  }
  get current(){return this.entries[this.index];}
  get canUndo(){return this.index>0;}
  get canRedo(){return this.index<this.entries.length-1;}
  get size(){return this.entries.length;}
  breakGroup(){this.lastKey=null;this.lastAt=0;}
  record(snapshot,groupKey=null,now=Date.now()){
    if(typeof snapshot!=='string')throw new TypeError('history_snapshot_must_be_string');
    if(snapshot===this.current)return false;
    const key=typeof groupKey==='string'?groupKey:null;
    const merge=key!==null && key===this.lastKey && this.index>0 &&
      this.index===this.entries.length-1 && now>=this.lastAt &&
      now-this.lastAt<=this.groupMs;
    if(this.canRedo){
      for(const value of this.entries.splice(this.index+1))this.bytes-=value.length*2;
    }
    if(merge){
      this.bytes-=this.entries[this.index].length*2;
      this.entries[this.index]=snapshot;
    }else{
      this.entries.push(snapshot);this.index++;
    }
    this.bytes+=snapshot.length*2;
    // Keep at least the current document, even when it alone exceeds the
    // history budget. Older snapshots are released, never page contents.
    while(this.entries.length>1 &&
        (this.entries.length>this.maxEntries||this.bytes>this.maxBytes)){
      this.bytes-=this.entries.shift().length*2;this.index--;
    }
    this.lastKey=key;this.lastAt=now;
    return true;
  }
  undo(){
    this.breakGroup();
    if(!this.canUndo)return null;
    this.index--;return this.current;
  }
  redo(){
    this.breakGroup();
    if(!this.canRedo)return null;
    this.index++;return this.current;
  }
}
if(typeof module==='object' && module.exports)module.exports=BlockHistory;
else host.VazinBlockHistory=BlockHistory;
})(globalThis);
