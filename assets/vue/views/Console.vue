<template>
  <div class="container-fluid">
    <div class="Terminal">
      <div class="Terminal__Toolbar">
        <p class="Toolbar__user">Terminal</p>
      </div>
      <div class="Terminal__body" id="term" v-if="auth && !settingsIsOpened">
        <button class="settings-btn" @click="settingsIsOpened = !settingsIsOpened">Settings</button>
        <button class="settings-btn clear-buf-btn" @click="commands = [{command: null, buffer: []}]">Clear buffer</button>
        <div class="Terminal__Prompt" v-for="(command, index) in commands" :key="index">
          <template v-if="index < commands.length && command.command !== null">
             <span class="pre-command">{{ command.connectionInfo }}:
              <span style="color: #6060ff;">{{command.pwd}}</span>
              <span style="color: white">$</span>
            </span>
            <input class="Prompt__cursor" disabled :value="command.command">
          </template>
          <pre class="buffer" v-if="command.buffer.length > 0">{{bufferToString(command.buffer)}}</pre>
          <div class="clearfix"></div>
          <template v-if="(index + 1) === commands.length">
            <span class="pre-command">{{ connectionInfo }}:
              <span style="color: #6060ff;">{{pwd}}</span>
              <span style="color: white">$</span>
            </span>
            <input class="Prompt__cursor" v-focus @keypress.enter="sendToShell" v-model="currentCommand">
          </template>
        </div>
      </div>
      <div class="Terminal__body" v-if="!auth || settingsIsOpened">
        <input placeholder="username" class="Prompt__cursor auth-input" v-model="variables.username">
        <input placeholder="pass" class="Prompt__cursor auth-input" v-model="variables.pass" type="password">
        <input placeholder="ip" class="Prompt__cursor auth-input" v-model="variables.ip">
        <pre v-if="authError" class="buffer">{{authError}}</pre>
        <button class="connect-btn" @click="connect">Connect</button>
      </div>
    </div>
  </div>

</template>

<script>
import Vue from 'vue';
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
      connectionInfo: window.localStorage.getItem('username') + '@' + window.localStorage.getItem('ip'),
      pwd: '~',
      variables: {
        username: window.localStorage.getItem('username'),
        pass: window.localStorage.getItem('pass'),
        ip: window.localStorage.getItem('ip'),
      },
      auth: false,
      authError: null,
      settingsIsOpened: false,
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
    }
  },
  computed: {
    username() {return this.variables.username},
    pass() {return this.variables.pass},
    ip() {return this.variables.ip},
  },
  updated() {
    let term = document.getElementById('term');
    term.scrollTop = term.scrollHeight;
  },
  methods: {
    loadPwd(data) {
      this.pwd = data.pwd;
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
          username: this.username
        }
      }));
    },
    loadBuffer(data) {
      this.commands[this.commands.length - 1].buffer = [];
      this.commands[this.commands.length - 1].buffer.push(data.buffer);
    },
    sendToShell() {
      let command = this.currentCommand;
      for (const [key, value] of Object.entries(this.variables)) {
        command = command.replace('{$' + key + '}', value);
      }
      this.commands.push({
        command: this.currentCommand,
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
      return array.join('');
    }
  }
}
</script>

<style>
.container-fluid {
  margin: 0;
  padding: 0;
}
</style>

<style scoped>
@import url('https://fonts.googleapis.com/css?family=Ubuntu+Mono');
@import url('https://fonts.googleapis.com/css?family=Ubuntu');

.buffer {
  color: white;
  width: 100%;
  font-family: 'Ubuntu Mono';
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
}

.pre-command {
  color: #81c781;
  font-weight: 600;
}

.Terminal__Toolbar {
  background: linear-gradient(#504b45 0%, #3c3b37 100%);
  width: 100%;
  padding: 5px 8px;
  box-sizing: border-box;
  height: 25px;
  align-items: center;
}

.Toolbar__user {
  color: #d5d0ce;
  margin-left: 4px;
  font-size: 12px;
  line-height: 14px;
  margin-bottom: 1px;
}

.Terminal__body {
  background: rgba(56, 4, 40, .9);
  height: calc(100% - 25px);
  margin-top: -1px;
  font-family: 'Ubuntu mono';
  overflow-x: hidden;
  overflow-y: scroll;
  padding: 10px;
}

.Terminal__body::-webkit-scrollbar {
  display: none;
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