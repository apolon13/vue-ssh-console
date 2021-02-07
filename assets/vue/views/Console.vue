<template>
  <div class="container-fluid">
    <div class="Terminal">
      <div class="Terminal__Toolbar">
        <hsc-menu-style-white>
          <hsc-menu-bar>
            <hsc-menu-bar-item label="Options">
              <hsc-menu-item label="Connect" :sync="true"
                             @click="settingsIsOpened = !settingsIsOpened"/>
            </hsc-menu-bar-item>
            <hsc-menu-bar-item label="Buffer">
              <hsc-menu-item label="Clear" :sync="true"
                             @click="commands = [{command: null, buffer: []}]"/>
            </hsc-menu-bar-item>
          </hsc-menu-bar>
        </hsc-menu-style-white>
      </div>
      <div class="Terminal__body" id="term" v-if="auth && !settingsIsOpened">
        <div class="Terminal__Prompt" v-for="(command, index) in commands"
             :key="index">
          <template v-if="index < commands.length && command.command !== null">
             <span class="pre-command" v-html="command.connectionInfo">:</span>
              <span style="color: white"> $</span>
            <input class="Prompt__cursor" disabled :value="command.command">
          </template>
          <pre class="buffer"
               v-if="command.buffer.length > 0" v-html="bufferToString(command.buffer)"></pre>
          <div class="clearfix"></div>
          <template v-if="(index + 1) === commands.length">
            <span class="pre-command" v-html="connectionInfo">:</span>
            <span style="color: white"> $</span>
            <input class="Prompt__cursor" v-focus
                   @keydown="commandByHistory"
                   @keypress.enter="sendToShell"
                   v-model="currentCommand">
          </template>
        </div>
      </div>
      <div class="Terminal__body" id="term" v-if="!auth || settingsIsOpened">
        <input placeholder="username" class="Prompt__cursor auth-input"
               v-model="variables.username">
        <input placeholder="pass" class="Prompt__cursor auth-input"
               v-model="variables.pass" type="password">
        <input placeholder="ip" class="Prompt__cursor auth-input"
               v-model="variables.ip">
        <div class="debug-mode">
          <input type="checkbox" v-model="variables.debug"> <label for=""
                                                                   style="color:white;">debug</label>
        </div>
        <pre v-if="authError" class="buffer">{{ authError }}</pre>
        <button class="connect-btn" @click="connect">Connect</button>
      </div>
    </div>
  </div>

</template>

<script>
import Vue from 'vue';
import * as VueMenu from '@hscmap/vue-menu'

Vue.use(VueMenu)

Vue.directive('focus', {
  inserted: function (el) {
    el.focus()
  }
})
export default {
  name: "Console",
  data() {
    return {
      currentCommand: null,
      connectionInfo: null,
      variables: {
        username: window.localStorage.getItem('username'),
        pass: window.localStorage.getItem('pass'),
        ip: window.localStorage.getItem('ip'),
        debug: window.localStorage.getItem('debug') === "true"
      },
      auth: false,
      authError: null,
      settingsIsOpened: false,
      positionInHistory: 0,
      commands: [
        {buffer: [], command: null}
      ],
      socket: {}
    }
  },
  mounted() {
    this.socket = new WebSocket('ws://localhost:8081');
    this.socket.onmessage = function (message) {
      let parsedMessage = JSON.parse(message.data);
      this[parsedMessage.handler](parsedMessage.data);
    }.bind(this)
  },
  watch: {
    username() {
      window.localStorage.setItem('username', this.username)
    },
    pass() {
      window.localStorage.setItem('pass', this.pass)
    },
    ip() {
      window.localStorage.setItem('ip', this.ip)
    },
    debug() {
      window.localStorage.setItem('debug', this.debug)
    }
  },
  computed: {
    username() {
      return this.variables.username
    },
    pass() {
      return this.variables.pass
    },
    ip() {
      return this.variables.ip
    },
    debug() {
      return this.variables.debug
    }
  },
  updated() {
    let term = document.getElementById('term');
    term.scrollTop = term.scrollHeight;
  },
  methods: {
    commandByHistory(event) {
      let isUp = event.key === 'ArrowUp';
      let isDown = event.key === 'ArrowDown';
      if (isUp || isDown) {
        let commands = this.commands.map((item) => {
          return item.command;
        });
        commands = commands.filter((item) => {
          return item !== null;
        });
        if (commands.length !== 0) {
          let origin = this.positionInHistory;
          let position = 0;
          if (isUp) {
            position = origin - 1;
          } else {
            position = origin + 1;
          }
          if (position && (position * -1) > commands.length) {
            Vue.set(this, 'positionInHistory', origin)
          } else if (position >= 0) {
            Vue.set(this, 'currentCommand', null)
            Vue.set(this, 'positionInHistory', 0)
          } else {
            Vue.set(this, 'positionInHistory', position)
            this.currentCommand = commands.splice(this.positionInHistory, 1)[0];
          }
        }
      }
    },
    authSuccess() {
      this.auth = true;
      this.authError = null;
      this.settingsIsOpened = false;
    },
    authFail(data) {
      this.auth = false;
      this.authError = data.error;
    },
    connect() {
      this.socket.send(JSON.stringify({
        method: 'connect',
        params: {
          ip: this.ip,
          pass: this.pass,
          username: this.username,
          debug: this.debug
        }
      }));
    },
    loadBuffer(data) {
      let key = this.commands.length - 1;
      if (typeof this.commands[key].buffer === "undefined") {
        this.commands[key].buffer = [];
      }
      this.commands[key].buffer.push(data.buffer);
      this.connectionInfo = data.connectionInfo ? data.connectionInfo : this.connectionInfo
    },
    sendToShell() {
      let command = this.currentCommand;
      for (const [key, value] of Object.entries(this.variables)) {
        command = command.replace('{$' + key + '}', value);
      }
      this.commands.push({
        command: command,
        pwd: this.pwd,
        connectionInfo: this.connectionInfo,
        buffer: []
      })
      this.socket.send(JSON.stringify({
        method: 'write',
        params: {
          command: command
        }
      }));
      this.currentCommand = null;
    },
    bufferToString(array) {
      return array.join('\r\n');
    }
  }
}
</script>

<style>
.container-fluid {
  margin: 0;
  padding: 0;
}

.menubar {
  padding: 0 !important;
}

.menu {
  border-radius: 0 !important;
}
</style>

<style scoped>
@import url('https://fonts.googleapis.com/css?family=Ubuntu+Mono');
@import url('https://fonts.googleapis.com/css?family=Ubuntu');

.buffer {
  color: white;
  width: 100%;
  font-family: 'Ubuntu Mono';
  word-wrap: break-word;
  -ms-word-break: break-all;
  word-break: break-word;
  -webkit-hyphens: auto;
  -moz-hyphens: auto;
  -ms-hyphens: auto;
  white-space: pre-wrap;
  white-space: -moz-pre-wrap;
  white-space: -pre-wrap;
  white-space: -o-pre-wrap;
  hyphens: auto;

}

.clear-buf-btn {
  top: 70px
}

body {
  background: linear-gradient(45deg, #57003f 0%, #f57453 100%);
  font-family: 'Ubuntu';
}

.connect-btn {
  margin-left: 10px;
}

.settings-btn {
  position: fixed;
  right: 20px;
}

.debug-mode {
  display: block;
  margin-bottom: 10px;
  margin-left: 10px;
}

.auth-input {
  width: 300px !important;
  display: block;
  padding: 2px !important;
  margin-bottom: 10px;
}

.container {
  justify-content: center;
  align-items: center;
  height: 100vh;

}

.Terminal {
  width: 100%;
  height: 100vh;
  box-shadow: 2px 4px 10px rgba(0, 0, 0, .5);
  overflow: hidden;
}

.pre-command {
  color: #81c781;
  font-weight: 600;
}

.Terminal__Toolbar {
  background: linear-gradient(#504b45 0%, #3c3b37 100%);
  width: 100%;
  padding: 0;
  box-sizing: border-box;
  align-items: center;
  position: fixed;
}

.Toolbar__user {
  color: #d5d0ce;
  margin-left: 4px;
  font-size: 12px;
  line-height: 14px;
  margin-bottom: 1px;
}

.Terminal__body {
  background: rgb(11 54 66);
  height: calc(100% - 25px);
  margin-top: 37px;
  font-family: 'Ubuntu mono';
  overflow-x: hidden;
  overflow-y: scroll;
  padding: 10px;
}

::-webkit-scrollbar {
  width: 20px;
}

::-webkit-scrollbar-track {
  background-color: transparent;
}

::-webkit-scrollbar-thumb {
  background-color: #d6dee1;
  border-radius: 20px;
  border: 6px solid transparent;
  background-clip: content-box;
}

::-webkit-scrollbar-thumb:hover {
  background-color: #a8bbbf;
}

.Prompt__cursor {
  margin-left: 10px;
  width: 70%;
  background: inherit;
  padding: 0;
  color: white;
  border: navajowhite;
}

.Prompt__cursor:focus {
  outline: none;
}

@keyframes blink {
  0% {
    opacity: 0;
  }
  100% {
    opacity: 1;
  }
}

@media (max-width: 600px) {
  .Terminal {
    max-height: 90%;
    width: 95%;
  }
}
</style>