const app = require('express')()
const http = require('http').Server(app)
const io = require('socket.io')(http)
var os = require('os');
var ifaces = os.networkInterfaces();
let localAddress = ''

Object.keys(ifaces).forEach(function (ifname) {
  var alias = 0

  ifaces[ifname].forEach(function (iface) {
    if ('IPv4' !== iface.family || iface.internal !== false) {
      // skip over internal (i.e. 127.0.0.1) and non-ipv4 addresses
      return
    }

    if (alias >= 1) {
      // this single interface has multiple ipv4 addresses
      console.log(ifname + ':' + alias, iface.address)
    } else {
      // this interface has only one ipv4 adress
      console.log(ifname, iface.address)
    }
    ++alias
  })
})

app.get('/', (req, res) => {
  res.sendFile('./templates/index.html')
})

http.listen(process.env.PORT, () => {
  console.log(`server listening on *:${process.env.PORT}`)
})

var usernames = []
var connectedUsers = 0
const PASSWORD = 'holi'

io.on('connection', socket => {
  connectedUsers++
  socket.authenticated = false

  socket.username = socket.id
  usernames.push(socket.username)
  socket.color = idToColor(socket.id)

  setInterval(() => {
    let resume = {
      'count': connectedUsers,
      'users': []
    }
    for (let id in io.sockets.connected) {
      if (io.sockets.connected[id].authenticated) {
        resume.users.push({
          'id': id,
          'username': io.sockets.connected[id].username,
          'color': io.sockets.connected[id].color
        })
      }
    }
    socket.emit('active users', resume)
  }, 10 * 1000)

  console.log(`User connected: ${socket.id}`)

  socket.on('auth', pw => {
    if (pw === PASSWORD) {
      socket.authenticated = true
      console.log(socket.authenticated)
    }
  })

  socket.on('disconnect', () => {
    connectedUsers--
    removeUsername(socket.username)
    console.log(`User disconnected: ${socket.id}`)
  })

  socket.on('chat message', msg => {
    if (!socket.authenticated) return false
    let now = new Date()
    let timestamp = [
      now.getDate() < 10 ? '0' + now.getDate() : now.getDate(), '/',
      now.getMonth() + 1 < 10 ? '0' + (now.getMonth() + 1) : now.getMonth() + 1, '/',
      now.getFullYear(), ' ',
      now.getHours() < 10 ? '0' + now.getHours() : now.getHours(), ':',
      now.getMinutes() < 10 ? '0' + now.getMinutes() : now.getMinutes(), ':',
      now.getSeconds() < 10 ? '0' + now.getSeconds() : now.getSeconds()
    ].join('')
    for (let id in io.sockets.connected) {
      if (io.sockets.connected[id].authenticated) {
        io.to(id).volatile.emit('chat message', {
          'msg': msg,
          'user': socket.username || socket.id,
          'color': socket.color,
          'ownMsg': socket.id === id,
          'timestamp': timestamp
        })
      }
    }
  })

  socket.on('set username', username => {
    if (!socket.authenticated) return false
    if (usernames.includes(username)) {
      return io.to(socket.id).emit('notification', { 'msg': 'El nombre de usuario ya está en uso' })
    }
    removeUsername(socket.username)
    socket.username = username
    usernames.push(username)
    // io.to(socket.id).emit('notification', { 'msg': 'Nombre de usuario cambiado' })
  })

  socket.on('set color', color => {
    socket.color = color
  })

  socket.on('get active users', () => {
    let resume = {
      'count': connectedUsers,
      'users': []
    }
    for (let id in io.sockets.connected) {
      if (io.sockets.connected[id].authenticated) {
        resume.users.push({
          'id': id,
          'username': io.sockets.connected[id].username,
          'color': io.sockets.connected[id].color
        })
      }
    }
    socket.emit('active users', resume)
  })

})

function removeUsername(username) {
  usernames.splice(usernames.indexOf(username), 1)
}

function hashCode(str) {
  var hash = 0
  for (var i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash)
  return hash
}

function intToRGB(i) {
  var c = (i & 0x00FFFFFF)
    .toString(16)
    .toUpperCase()

  return "00000".substring(0, 6 - c.length) + c
}

function idToColor(userid) {
  return '#' + intToRGB(hashCode(userid))
}